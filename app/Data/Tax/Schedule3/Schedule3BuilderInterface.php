<?php

namespace App\Data\Tax\Schedule3;

interface Schedule3BuilderInterface
{
    public function build(int $taxYear): Schedule3Data;
}
