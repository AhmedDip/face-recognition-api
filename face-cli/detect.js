const canvas  = require('canvas');
const faceapi = require('@vladmandic/face-api/dist/face-api.node-wasm.js');
const path    = require('path');

faceapi.env.monkeyPatch({
  Canvas:    canvas.Canvas,
  Image:     canvas.Image,
  ImageData: canvas.ImageData,
});

(async () => {
  const imgPath = process.argv[2];
  if (!imgPath) {
    process.stdout.write(JSON.stringify({ error: 'no image path' }));
    process.exit(1);
  }

  await faceapi.tf.setBackend('wasm');
  await faceapi.tf.ready();

  const modelDir = path.join(__dirname, 'weights');
  await faceapi.nets.tinyFaceDetector.loadFromDisk(modelDir);
  await faceapi.nets.faceLandmark68Net.loadFromDisk(modelDir);
  await faceapi.nets.faceRecognitionNet.loadFromDisk(modelDir);

  const img = await canvas.loadImage(imgPath);
  const det = await faceapi
    .detectSingleFace(img, new faceapi.TinyFaceDetectorOptions())
    .withFaceLandmarks()
    .withFaceDescriptor();

  if (!det) {
    process.stdout.write(JSON.stringify({ detected: false }));
    return;
  }

  process.stdout.write(JSON.stringify({
    detected:   true,
    descriptor: Array.from(det.descriptor),
    box:        det.detection.box,
  }));
})().catch((e) => {
  process.stdout.write(JSON.stringify({ error: e.message }));
  process.exit(1);
});
