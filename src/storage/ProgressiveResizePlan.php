<?php

namespace modules\files\storage;

final class ProgressiveResizePlan
{
    /**
     * Build in-memory downsampling stages for a target width.
     *
     * The final stage is always the requested width. For large reductions,
     * the image is first reduced to roughly five times the target size,
     * following the strategy used by ImageMagick's thumbnail operation.
     * Stages are applied in memory, without lossy file I/O.
     *
     * @return list<int>
     */
    public static function forWidth(int $sourceWidth, int $targetWidth): array
    {
        if ($sourceWidth <= $targetWidth || $targetWidth <= 0) {
            return [];
        }

        $preShrinkWidth = $targetWidth * 5;

        if ($preShrinkWidth < $sourceWidth) {
            return [$preShrinkWidth, $targetWidth];
        }

        return [$targetWidth];
    }
}
