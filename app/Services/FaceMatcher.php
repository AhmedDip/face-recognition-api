<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FaceMatcher
{
    /**
     * 1:1 — শুধু একজন নির্দিষ্ট staff-এর সাথে তুলনা
     *
     * @return array{0: bool, 1: ?float, 2: ?array}
     *         [verified, distance, staffRow]
     */
    public function compareToStaff(string $staffId, array $descriptor, ?float $threshold = null): array
    {
        if (count($descriptor) !== 128 || ! $this->isFinite($descriptor)) {
            return [false, null, null];
        }

        $threshold ??= (float) config('services.face_recognition.threshold', 0.5);

        $row = DB::table('tm_aemp')
            ->select(['id', 'aemp_name', 'aemp_usnm', 'aemp_dcpt'])
            ->where('aemp_usnm', $staffId)
            ->first();

        if ($row === null) {
            return [false, null, null];   
        }

        $stored = $this->decodeDescriptor($row->aemp_dcpt);
        if ($stored === null) {
            return [false, null, [
                'staff_id'       => $row->aemp_usnm,
                'name'           => $row->aemp_name,
                'has_descriptor' => false,
            ]];
        }

        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = $stored[$i] - $descriptor[$i];
            $sum += $diff * $diff;
        }
        $distance = sqrt($sum);

        return [
            $distance <= $threshold,
            $distance,
            [
                'staff_id'       => $row->aemp_usnm,
                'name'           => $row->aemp_name,
                'has_descriptor' => true,
            ],
        ];
    }

    /**
     * 1:N — সবাইকে খুঁজে সবচেয়ে কাছের জন (আগেরটা, রাখো)
     *
     * @return array{0: ?array, 1: float}
     */
    public function findClosest(array $descriptor, ?float $threshold = null): array
    {
        if (count($descriptor) !== 128 || ! $this->isFinite($descriptor)) {
            return [null, INF];
        }

        $threshold ??= (float) config('services.face_recognition.threshold', 0.5);
        $best         = null;
        $bestDistance = INF;

        DB::table('tm_aemp')
            ->select(['aemp_id', 'aemp_name', 'aemp_usnm', 'aemp_dcpt'])
            ->whereNotNull('aemp_dcpt')
            ->orderBy('aemp_id')
            ->lazyById(1000, 'aemp_id')
            ->each(function ($row) use ($descriptor, &$best, &$bestDistance) {
                $stored = $this->decodeDescriptor($row->aemp_dcpt);
                if ($stored === null) return;

                $sum = 0.0;
                for ($i = 0; $i < 128; $i++) {
                    $diff = $stored[$i] - $descriptor[$i];
                    $sum += $diff * $diff;
                }
                $distance = sqrt($sum);

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = [
                        'staff_id' => $row->aemp_usnm,
                        'name'     => $row->aemp_name,
                        'aemp_id'  => $row->aemp_id,
                    ];
                }
            });

        if ($best === null || $bestDistance > $threshold) {
            return [null, $bestDistance];
        }

        return [$best, $bestDistance];
    }

    /** @return list<float>|null */
    private function decodeDescriptor($raw): ?array
    {
        if ($raw === null || $raw === '') return null;

        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($arr) || count($arr) !== 128) return null;

        $out = [];
        foreach ($arr as $v) {
            if (! is_float($v) && ! is_int($v)) return null;
            $f = (float) $v;
            if (! is_finite($f)) return null;
            $out[] = $f;
        }
        return $out;
    }

    private function isFinite(array $d): bool
    {
        foreach ($d as $v) {
            if (! is_float($v) && ! is_int($v)) return false;
            if (! is_finite((float) $v)) return false;
        }
        return true;
    }
}
