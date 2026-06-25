<?php

namespace Restruct\SilverStripe\ImageCropper;

use JonoM\FocusPoint\Forms\FocusPointField;
use SilverStripe\Assets\File;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;

/**
 * Adds the (SS6 React-safe) image cropper UI to the asset-admin image edit form.
 *
 * SS6 note: the asset-admin form is fully React. We therefore expose the cropper as a
 * self-contained LiteralField mount (rendered as raw HTML, which React treats as opaque)
 * carrying all data the vanilla-JS cropper needs via data-attributes. The actual crop
 * JSON lives in a hidden-by-CSS CropData TextField that the JS writes through React's
 * native value setter. This avoids any dependency on jQuery or on focuspoint's DOM.
 *
 * @extends Extension
 */
class ImageCropperFormFactoryExtension
    extends Extension
{
    public function updateFormFields($fields, $controller, $formName, $context)
    {
        /** @var File $record */
        $record = isset($context['Record']) ? $context['Record'] : null;
        if (!$record || !$record->hasField('CropData')) {
            return;
        }

        // Downsized preview both modes operate on (same sizing as FocusPointField).
        $previewImage = $record->FitMax(
            FocusPointField::config()->get('max_width'),
            FocusPointField::config()->get('max_height')
        );
        if (!$previewImage) {
            return;
        }

        // The main file edit form gets the interactive cropper; every other form (e.g.
        // the insert/select dialog used when picking an image in the content editor)
        // gets a read-only preview that shows the crop area and the focus point.
        if ($formName === 'fileEditForm') {
            $this->addEditableCropper($fields, $record, $previewImage);
        } else {
            $this->addReadonlyPreview($fields, $record, $previewImage);
        }
    }

    /**
     * Interactive crop + focus widget (driven by sscropper.js).
     */
    protected function addEditableCropper($fields, $record, $previewImage)
    {
        // Value store for the crop JSON (kept offscreen via CSS, written by sscropper.js).
        $fields->insertAfter('Title', TextField::create('CropData', 'CropData', $record->CropData));

        $sizes = [
            'originalWidth' => $record->getWidth(),
            'originalHeight' => $record->getHeight(),
            'previewWidth' => $previewImage->getWidth(),
            'previewHeight' => $previewImage->getHeight(),
        ];

        // Self-contained mount point for the vanilla-JS cropper. Rendered as raw HTML so
        // React leaves the cropper DOM we attach inside it untouched.
        $mount = sprintf(
            '<div class="sscropper" data-cropconfig="%s" data-cropsizing="%s" data-imageurl="%s" data-imagewidth="%d" data-imageheight="%d"></div>',
            Convert::raw2att(json_encode($record->config()->get('cropconfig'))),
            Convert::raw2att(json_encode($sizes)),
            Convert::raw2att($previewImage->getURL()),
            $previewImage->getWidth(),
            $previewImage->getHeight()
        );
        $fields->insertAfter('CropData', LiteralField::create('SSCropperUI', $mount));
    }

    /**
     * Static, non-editable preview: dims everything outside the stored crop rectangle
     * and marks the stored focus point. Computed entirely server-side (no JS needed).
     */
    protected function addReadonlyPreview($fields, $record, $previewImage)
    {
        $pw = (int) $previewImage->getWidth();
        $ph = (int) $previewImage->getHeight();
        $ow = (int) $record->getWidth();
        $oh = (int) $record->getHeight();
        if ($pw < 1 || $ph < 1 || $ow < 1 || $oh < 1) {
            return;
        }

        // Crop rectangle (CropData is stored relative to the original image).
        $cropHtml = '';
        $crop = $record->CropData ? json_decode($record->CropData) : null;
        if ($crop && isset($crop->originalWidth, $crop->originalHeight)) {
            $scaleX = $pw / $ow;
            $scaleY = $ph / $oh;
            $cropHtml = sprintf(
                '<div class="sscropper-preview__crop" style="left:%1$.2fpx;top:%2$.2fpx;width:%3$.2fpx;height:%4$.2fpx;"></div>',
                ((float) $crop->originalX) * $scaleX,
                ((float) $crop->originalY) * $scaleY,
                ((float) $crop->originalWidth) * $scaleX,
                ((float) $crop->originalHeight) * $scaleY
            );
        }

        // Focus point (stored as X/Y in the range -1..1).
        $focusX = 0.0;
        $focusY = 0.0;
        $fp = $record->getField('FocusPoint');
        if ($fp && method_exists($fp, 'getX')) {
            $focusX = (float) $fp->getX();
            $focusY = (float) $fp->getY();
        }
        $focusHtml = sprintf(
            '<span class="sscropper-preview__focus" style="left:%1$.2fpx;top:%2$.2fpx;"></span>',
            ($focusX + 1) * 0.5 * $pw,
            ($focusY + 1) * 0.5 * $ph
        );

        $html = sprintf(
            '<div class="sscropper-preview" style="width:%1$dpx;height:%2$dpx;"><img src="%3$s" width="%1$d" height="%2$d" alt="">%4$s%5$s</div>',
            $pw,
            $ph,
            Convert::raw2att($previewImage->getURL()),
            $cropHtml,
            $focusHtml
        );

        $preview = LiteralField::create('SSCropperPreview', $html);
        if ($fields->fieldByName('Title')) {
            $fields->insertAfter('Title', $preview);
        } else {
            $fields->push($preview);
        }
    }
}
