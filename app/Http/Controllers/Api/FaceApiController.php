<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FaceDetectionService;
use App\Services\FaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FaceApiController extends Controller
{
    public function __construct(
        private FaceDetectionService $detector,
        private FaceMatcher $matcher,
    ) {}

    /**
     * POST /api/v1/recognize
     * Body: { image: "data:image/jpeg;base64,..." }
     */
   public function recognize(Request $request): JsonResponse
{
    $data = $request->validate([
        'staff_id' => ['required', 'string', 'max:255'],
        'image'    => ['required', 'string', 'max:10485760'],
    ]);

    try {
        $detection = $this->detector->detectFromBase64($data['image']);
    } catch (InvalidArgumentException $e) {
        return response()->json(['error' => $e->getMessage()], 422);
    } catch (RuntimeException $e) {
        return response()->json(['error' => $e->getMessage()], 503);
    }

    if (! ($detection['detected'] ?? false)) {
        return response()->json([
            'verified' => false,
            'reason'   => 'no_face',
        ]);
    }

    try {
        $descriptor = $this->descriptorFromDetection($detection);
    } catch (InvalidArgumentException $e) {
        return response()->json(['error' => $e->getMessage()], 422);
    }

    $threshold = (float) config('services.face_recognition.threshold', 0.5);

    [$verified, $distance, $staff] = $this->matcher->compareToStaff(
        $data['staff_id'],
        $descriptor,
        $threshold,
    );

    // staff নেই
    if ($staff === null) {
        return response()->json(['error' => 'Staff not found'], 404);
    }

    // staff আছে কিন্তু enrollment হয়নি
    if (! $staff['has_descriptor']) {
        return response()->json([
            'verified' => false,
            'staff_id' => $staff['staff_id'],
            'name'     => $staff['name'],
            'reason'   => 'not_enrolled',
        ], 409);
    }

    return response()->json([
        'verified'  => $verified,
        'staff_id'  => $staff['staff_id'],
        'name'      => $staff['name'],
        'distance'  => $distance,
        'threshold' => $threshold,
    ]);
}

    /**
     * POST /api/v1/people/enroll
     * Body: { staff_id: "EMP001", image: "data:image/jpeg;base64,..." }
     * tm_aemp-এ aemp_dcpt আপডেট করে
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id' => ['required', 'string', 'max:255'],
            'image'    => ['required', 'string', 'max:10485760'],
        ]);

        $exists = DB::table('tm_aemp')->where('aemp_usnm', $data['staff_id'])->exists();
        if (! $exists) {
            return response()->json(['error' => 'Staff not found'], 404);
        }

        try {
            $detection = $this->detector->detectFromBase64($data['image']);
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

        try {
            DB::table('tm_aemp')
                ->where('aemp_usnm', $data['staff_id'])
                ->update([
                    'aemp_dcpt' => json_encode($descriptor),
                ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to save descriptor'], 500);
        }

        $row = DB::table('tm_aemp')
            ->select(['aemp_usnm', 'aemp_name'])
            ->where('aemp_usnm', $data['staff_id'])
            ->first();

        return response()->json([
            'updated'  => true,
            'staff_id' => $row->aemp_usnm,
            'name'     => $row->aemp_name,
        ]);
    }

    /**
     * GET /api/v1/people?q=...&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tm_aemp')
            ->select(['aemp_id', 'aemp_name', 'aemp_usnm', 'aemp_dcpt']);

        if ($search = $request->query('q')) {
            $query->where(function ($w) use ($search) {
                $w->where('aemp_name', 'like', "%{$search}%")
                  ->orWhere('aemp_usnm', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('aemp_id')->paginate(20);

        // descriptor বাদ দিয়ে শুধু আছে/নেই জানাও
        $paginator->getCollection()->transform(function ($row) {
            $hasDescriptor = false;

            if (is_string($row->aemp_dcpt) && $row->aemp_dcpt !== '') {
                $decoded = json_decode($row->aemp_dcpt, true);
                $hasDescriptor = is_array($decoded) && count($decoded) === 128;
            }

            return [
                'staff_id'       => $row->aemp_usnm,
                'name'           => $row->aemp_name,
                'has_descriptor' => $hasDescriptor,
            ];
        });

        return response()->json($paginator);
    }

    /**
     * DELETE /api/v1/people/{staffId}/descriptor
     */
    public function clearDescriptor(string $staffId): JsonResponse
    {
        $exists = DB::table('tm_aemp')->where('aemp_usnm', $staffId)->exists();
        if (! $exists) {
            return response()->json(['error' => 'Staff not found'], 404);
        }

        DB::table('tm_aemp')
            ->where('aemp_usnm', $staffId)
            ->update(['aemp_dcpt' => null]);

        return response()->json(['cleared' => true, 'staff_id' => $staffId]);
    }

    /** @return list<float> */
    private function descriptorFromDetection(array $detection): array
    {
        $d = $detection['descriptor'] ?? null;
        if (! is_array($d) || count($d) !== 128) {
            throw new InvalidArgumentException('Invalid descriptor from detector');
        }
        $d = array_map('floatval', $d);
        foreach ($d as $v) {
            if (! is_finite($v)) {
                throw new InvalidArgumentException('Invalid descriptor from detector');
            }
        }
        return $d;
    }
}
