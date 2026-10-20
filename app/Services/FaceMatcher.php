<?php

namespace App\Services;

use App\Models\Person;

class FaceMatcher
{
    /** @return array{0: ?Person, 1: float} */
    public function findClosest(array $descriptor, ?float $threshold = null): array
    {
        if (count($descriptor) !== 128 || ! $this->isFiniteDescriptor($descriptor)) {
            return [null, INF];
        }

        $threshold ??= (float) config('services.face_recognition.threshold', 0.5);
        $best         = null;
        $bestDistance = INF;

        Person::query()
            ->select(['id', 'name', 'email', 'photo', 'descriptor'])
            ->each(function (Person $person) use ($descriptor, &$best, &$bestDistance) {
                if (
                    ! is_array($person->descriptor)
                    || count($person->descriptor) !== 128
                    || ! $this->isFiniteDescriptor($person->descriptor)
                ) {
                    return;
                }

                $distance = sqrt(array_sum(array_map(
                    fn (float|int $stored, float|int $incoming): float => ($stored - $incoming) ** 2,
                    $person->descriptor,
                    $descriptor,
                )));

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best         = $person;
                }
            });

        if ($best === null || $bestDistance > $threshold) {
            return [null, $bestDistance];
        }

        return [$best, $bestDistance];
    }

    private function isFiniteDescriptor(array $descriptor): bool
    {
        foreach ($descriptor as $value) {
            if (! is_float($value) && ! is_int($value)) {
                return false;
            }

            if (! is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    }
}
