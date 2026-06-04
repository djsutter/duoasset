<?php

namespace App\Traits;

trait NormalizesNumericFields
{
    protected function normalizeNumericFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = str_replace(',', '', $data[$field]);
            }
        }

        return $data;
    }
}
