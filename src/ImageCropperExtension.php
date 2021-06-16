<?php

namespace Restruct\SilverStripe\ImageCropper;

use JonoM\FocusPoint\FieldType\DBFocusPoint;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;

/**
 * ImageCropper extension
 * Regular ->Fit() etc methods come from trait ImageManipulation which is applied on Image::class from its parent File::class
 *
 * @extends DataExtension
 * @property DBFile|Image $owner
 */
class ImageCropperExtension
    extends DataExtension
{
    /**
     * Field to hold cropdata
     */
    private static $db = [
        'CropData' => 'Varchar(255)', //stores json
    ];

    /**
     * @Config
     * These options are fed directly to the js cropper (options: https://github.com/fengyuanchen/cropper/blob/v2.3.0/README.md#options)
     */
    private static $cropconfig = array(
        //'aspectRatio' => 1,
//        'autoCrop' => false,
        'autoCropArea' => 1,
        'movable' => false,
        'rotatable' => false,
        'scalable' => false,
        'zoomable' => false,
    );

    public function onBeforeWrite()
    {
        // @TODO: check if we still need this:
//        if (Config::inst()->get(get_parent_class(), 'flush_on_change') && $this->owner->isChanged('CropData')) {
//            $this->owner->deleteFormattedImages();
//        }
        parent::onBeforeWrite();
    }

    /**
     * Apply the crop to this image (if any) and just return the cropped image.
     * Use in templates with $CroppedImage
     *
     * Use {@see CroppedFocusFillMax} to prevent upscaling
     *
     * @param int $width Width to crop to
     * @param int $height Height to crop to
     *
     * @return AssetContainer
     */
    public function CroppedImage()
    {
        return $this->applyCropManipulation();
    }


    //
    // 'GENERAL' image functions, cropper-flavoured
    //

    //ScaleMaxWidth(150)
    public function CroppedScaleWidth(int $width)
    {
        return $this->applyCropManipulation()->ScaleWidth($width);
    }

    //ScaleMaxWidth(150)
    public function CroppedScaleMaxWidth(int $width)
    {
        return $this->applyCropManipulation()->ScaleMaxWidth($width);
    }

    //ScaleHeight(150)
    public function CroppedScaleHeight(int $height)
    {
        return $this->applyCropManipulation()->ScaleHeight($height);
    }

    //ScaleMaxHeight(150)
    public function CroppedScaleMaxHeight(int $height)
    {
        return $this->applyCropManipulation()->ScaleMaxHeight($height);
    }

    //Fit(300,300)
    public function CroppedFit(int $width, int $height)
    {
        return $this->applyCropManipulation()->Fit($width, $height);
    }

    //FitMax(300,300)
    public function CroppedFitMax(int $width, int $height)
    {
        return $this->applyCropManipulation()->FitMax($width, $height);
    }

    //ResizedImage(200, 300)
    public function CroppedResizedImage(int $width, int $height)
    {
        return $this->applyCropManipulation()->ResizedImage($width, $height);
    }

    //Fill(150,150)
    public function CroppedFill(int $width, int $height)
    {
        return $this->applyCropManipulation()->Fill($width, $height);
    }

    //FillMax(150,150)
    public function CroppedFillMax(int $width, int $height)
    {
        return $this->applyCropManipulation()->FillMax($width, $height);
    }

    //CropWidth(150)
    public function CroppedCropWidth(int $width)
    {
        return $this->applyCropManipulation()->CropWidth($width);
    }

    //CropHeight(50)
    public function CroppedCropHeight(int $height)
    {
        return $this->applyCropManipulation()->CropHeight($height);
    }

    //Pad(100, 100, CCCCCC)
    public function CroppedPad(int $width, int $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0)
    {
        return $this->applyCropManipulation()->Pad($width, $height, $backgroundColor, $transparencyPercent);
    }


    //
    // 'FOCUS' image functions, cropper-flavoured
    //

    //FocusFill(int $width, int $height)
    public function CroppedFocusFill(int $width, int $height)
    {
        return $this->applyCropManipulation($width, $height)->FocusFill($width, $height);
    }

    //FocusFillMax(int $width, int $height)
    public function CroppedFocusFillMax(int $width, int $height)
    {
        return $this->applyCropManipulation($width, $height)->FocusFillMax($width, $height);
    }

    //FocusCropWidth(int $width)
    public function CroppedFocusCropWidth(int $width)
    {
        return $this->applyCropManipulation($width)->FocusCropWidth($width);
    }

    //FocusCropHeight(int $height)
    public function CroppedFocusCropHeight(int $height)
    {
        return $this->applyCropManipulation($height)->FocusCropHeight($height);
    }


    //
    // (LEGACY) ALIASES
    //

    public function CroppedFocusedImage($width, $height)
    {
        return $this->CroppedFocusFill($width, $height);
    }

    public function CroppedImageOnly($width, $height)
    {
        return $this->CroppedFill($width, $height);
    }


    //
    // BASE CROPPER METHOD
    //

    /**
     * Resize and crop image to fill specified dimensions, WHILE first applying the manual cropper selection
     * Use in templates with $CroppedImage
     *
     * @param int $width Width to crop to
     * @param int $height Height to crop to
     * @return AssetContainer
     */
    private function applyCropManipulation(?int $width=null, ?int $height=null, bool $upscale=true)
    {
        // originalX"]=> int(666) ["originalY"]=> int(238) ["originalWidth"]=> int(1342) ["originalHeight
        $cropData = json_decode( $this->owner->CropData );
        if (
            $cropData // If we have data and the properties we need are defined
            && property_exists($cropData, 'originalX') && property_exists($cropData, 'originalY')
            && property_exists($cropData, 'originalWidth') && property_exists($cropData, 'originalHeight')
            // AND at least width or height is different from original
//            && ($cropData->originalWidth != $this->owner->width || $cropData->originalHeight != $this->owner->height)
            && ($cropData->originalWidth != $this->owner->getWidth() || $cropData->originalHeight != $this->owner->getHeight())
        ) {
            // Apparently the SSv4 way of manipulating images;
            $variantName = $this->owner->variantName('cropped', $cropData->originalX, $cropData->originalY, $cropData->originalWidth, $cropData->originalHeight);
            $newImage = $this->owner->manipulateImage($variantName, function (Image_Backend $backend) use ($cropData) {
                // Apply crop
                return $backend->crop($cropData->originalY, $cropData->originalX, $cropData->originalWidth, $cropData->originalHeight);
            });

            // recalculate (offset) & set FocusPoint data on new image (based on FocusPointExtension)
            if (!$newImage) { return null; }

            // perform some recalculations
            $FPX_orig_relZeroBased = ($this->owner->FocusPointX +1) / 2; // eg at 100 of 200 width cropped from X 60 to 110px width (right offset 170, right margin 30)
            $FPX_orig_abs = $FPX_orig_relZeroBased * $this->owner->FocusPointWidth; // eg at 100 of 200 width cropped from X 60 to 110px width (right offset 170, right margin 30)
            $FPX_new_abs = $FPX_orig_abs - $cropData->originalX; // eg 100 (orig x) - 60 (left crop offset) = 40
            $FPX_new_relZeroBased = 1 / $cropData->originalWidth * $FPX_new_abs;
            $FPX_new_rel = $FPX_new_relZeroBased * 2 - 1;

            $FPY_orig_relZeroBased = ($this->owner->FocusPointY + 1) / 2;
            $FPY_orig_abs = $FPY_orig_relZeroBased * $this->owner->FocusPointHeight;
            $FPY_new_abs = $FPY_orig_abs - $cropData->originalY;
            $FPY_new_relZeroBased = 1 / $cropData->originalHeight * $FPY_new_abs;
            $FPY_new_rel = $FPY_new_relZeroBased * 2 - 1;
//var_dump("this->owner->FocusPointY: {$this->owner->FocusPointY}");
//var_dump("FPY_orig_relZeroBased = (this->owner->FocusPointY:{$this->owner->FocusPointY} + 1) / 2 = $FPY_orig_relZeroBased");
//var_dump("FPY_orig_abs = FPY_orig_relZeroBased * this->owner->FocusPointHeight:{$this->owner->FocusPointHeight} = $FPY_orig_abs");
//var_dump("FPY_new_abs = FPY_orig_abs:$FPY_orig_abs - cropData->originalY:{$cropData->originalY} = $FPY_new_abs");
//var_dump("FPY_new_relZeroBased = 1 / cropData->originalHeight:{$cropData->originalHeight} * FPY_new_abs:$FPY_new_abs = $FPY_new_relZeroBased");
//var_dump("FPY_new_rel = FPY_new_relZeroBased:$FPY_new_relZeroBased * 2 - 1 = $FPY_new_rel");
//die();

            // Force refresh of new focus point for DBFile (Image dataobject uses its own value)
            if ($newImage instanceof DBFile) {
                $newFocusPoint = DBFocusPoint::create();
                $newFocusPoint->setValue([
                    'X'      => $FPX_new_rel,
                    'Y'      => $FPY_new_rel,
                    'Width'  => $cropData->originalWidth,
                    'Height' => $cropData->originalHeight,
                ], $newImage);
                $newImage->FocusPoint = $newFocusPoint;
            }

            return $newImage;
        }

        // Else just return the full image(?)
        return $this->owner;
    }

}
