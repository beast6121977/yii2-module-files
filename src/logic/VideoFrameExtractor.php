<?php


namespace modules\files\logic;

use ErrorException;
use Yii;

class VideoFrameExtractor
{
    /** @var string */
    private $ffmpegBin;
    /** @var string */
    private $videoSourceFilename;
    /** @var string */
    private $outputImageFilename;

    public function __construct(string $videoSourceFilename, string $outputImageFilename)
    {
        $this->ffmpegBin = Yii::$app->getModule('files')->ffmpeg;

        if (!file_exists($this->ffmpegBin))
            throw new ErrorException("ffmpeg is not found: {$this->ffmpegBin}");

        if (!is_executable($this->ffmpegBin))
            throw new ErrorException("ffmpeg is not executable: {$this->ffmpegBin}");

        if (!is_file($videoSourceFilename))
            throw new ErrorException('File not found on disk.');

        $this->videoSourceFilename = $videoSourceFilename;
        $this->outputImageFilename = $outputImageFilename;
    }

    /**
     * @throws ErrorException
     */
    public function extract()
    {
        foreach (['00:00:05.000', '00:00:03.000', '00:00:01.000'] as $timestamp) {
            $command = implode(' ', [
                escapeshellarg($this->ffmpegBin),
                '-y',
                '-ss', escapeshellarg($timestamp),
                '-i', escapeshellarg($this->videoSourceFilename),
                '-frames:v', '1',
                '-f', 'image2',
                escapeshellarg($this->outputImageFilename),
            ]);

            $out = [];
            exec($command . ' 2>&1', $out, $result);
            if (is_file($this->outputImageFilename) && @getimagesize($this->outputImageFilename) !== false) {
                return true;
            }
        }

        throw new ErrorException('FFmpeg frame extracting fail:' . print_r($out, true));
    }

}
