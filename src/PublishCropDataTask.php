<?php

use SilverStripe\Assets\Image;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;

class PublishCropDataTask
    extends BuildTask
{
    private static $segment = 'PublishCropDataTask';

    protected $title = 'Hydrate Live images missing crop data';

    protected $description = 'Run this task to update live versions of images which are missing CropData';

    public $chunksize = 100;

    /**
     * @param HTTPRequest $request
     * @throws ValidationException
     */
    public function run($request)
    {
        // Get all Live images missing CropData
        $imageIDs = Versioned::get_by_stage(Image::class, Versioned::DRAFT)
                        ->filter('CropData:not', null)->column('ID');
        if(count($imageIDs)){
            $images = Versioned::get_by_stage(Image::class, Versioned::LIVE)
                        ->filter([
                            'CropData' => null,
                            'ID' => $imageIDs,
                        ]);

            print("Found {$images->count()} images to publish/hydrate<br><br>");

            if($images->count()){
                $count = 0;
                /** @var Image $image */
                foreach ($images as $image) {
                    $count++;
                    if($count > $this->chunksize){
                        die("Did {$this->chunksize} items... (please reload to process next chunk)");
                    }

                    // Skip images that aren't on the filesystem
                    if (!$image->exists()) {
                        continue;
                    }

                    // (Re)Publish
                    $draftImage = Versioned::get_by_stage(Image::class, Versioned::DRAFT)
                        ->byID($image->ID);

                    if ($draftImage->isPublished()) {
                        $draftImage->publishSingle();
                    }
                }
            }
        }

        print('ALL DONE!!!');
    }
}
