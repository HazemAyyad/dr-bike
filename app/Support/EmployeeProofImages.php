<?php

namespace App\Support;

final class EmployeeProofImages
{
    public static function has(mixed $images): bool
    {
        if ($images === null) {
            return false;
        }

        if (is_array($images)) {
            foreach ($images as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return true;
                }
                if (is_array($item) && ! empty($item)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($images)) {
            $trimmed = trim($images);

            return $trimmed !== '' && $trimmed !== '[]' && $trimmed !== 'null';
        }

        return ! empty($images);
    }
}
