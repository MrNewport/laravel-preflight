<?php

namespace MrNewport\LaravelPreflight;

interface Check
{
    /** @return list<Result> */
    public function run(): array;
}
