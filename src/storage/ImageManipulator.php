<?php

namespace modules\files\storage;

class ImageManipulator
{
    private $strategy;

    public function __construct(
        public string $file_extension
    )
    {

    }
}