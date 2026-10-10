<?php

namespace modules\files\storage;

interface ImageManipulatorInterface
{
    public function optimize();

    public function resize();

    public function watermark();
}