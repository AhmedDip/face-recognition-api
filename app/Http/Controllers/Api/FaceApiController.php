<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\FaceDetectionService;
use App\Services\FaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FaceApiController extends Controller
{
    public function __construct(
        private FaceDetectionService $detector,
        private FaceMatcher $matcher,
    ) {}

    public function recognize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'string', 'max:10485760'],
        ]);

        try {
            $detection = $this->detector->detectFromBase64($data['image']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        if (! ($detection['detected'] ?? false)) {
            return response()->json(['matched' => false, 'person' => null, 'reason' => 'no_face']);
        }

        try {
            $descriptor = $this->descriptorFromDetection($detection);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $threshold = (float) config('services.face_recognition.threshold', 0.5);

        [$person, $distance] = $this->matcher->findClosest($descriptor, $threshold);

        if ($person === null) {
            return response()->json([
                'matched'   => false,
                'person'    => null,
                'distance'  => is_finite($distance) ? $distance : null,
                'threshold' => $threshold,
            ]);
        }

        return response()->json([
            'matched' => true,
            'person'  => [
                'id'    => $person->id,
                'name'  => $person->name,
                'email' => $person->email,
                'photo' => $person->photo ? Storage::disk('public')->url($person->photo) : null,
            ],
            'distance'  => $distance,
            'threshold' => $threshold,
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(Person::latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'image' => ['required', 'string', 'max:10485760'],
        ]);

        try {
            $detection = $this->detector->detectFromBase64($data['image']);
            $image     = $this->detector->decodeBase64Image($data['image']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        if (! ($detection['detected'] ?? false)) {
            return response()->json(['error' => 'No face detected in image'], 422);
        }

        try {
            $descriptor = $this->descriptorFromDetection($detection);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $filename = 'people/'.Str::uuid().'.jpg';
        if (! Storage::disk('public')->put($filename, $image)) {
            return response()->json(['error' => 'Unable to store image'], 503);
        }

        try {
            $person = DB::transaction(fn () => Person::create([
                'name'       => $data['name'],
                'email'      => $data['email'] ?? null,
                'photo'      => $filename,
                'descriptor' => $descriptor,
            ]));
        } catch (Throwable $e) {
            Storage::disk('public')->delete($filename);
            throw $e;
        }

        return response()->json([
            'id'    => $person->id,
            'name'  => $person->name,
            'email' => $person->email,
            'photo' => Storage::disk('public')->url($filename),
        ], 201);
    }

    public function destroy(Person $person): JsonResponse
    {
        if ($person->photo) {
            Storage::disk('public')->delete($person->photo);
        }

        $person->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return list<float> */
    private function descriptorFromDetection(array $detection): array
    {
        $descriptor = $detection['descriptor'] ?? null;
        if (! is_array($descriptor) || count($descriptor) !== 128) {
            throw new InvalidArgumentException('Face detection returned an invalid descriptor');
        }

        $descriptor = array_map('floatval', $descriptor);
        foreach ($descriptor as $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Face detection returned an invalid descriptor');
            }
        }

        return $descriptor;
    }
}
