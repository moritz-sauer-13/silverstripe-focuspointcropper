import Cropper from 'cropperjs';

// SilverStripe 6 (vanilla JS, no jQuery, React-safe) combined crop + focus widget.
// The original implementation relied on jQuery ($) and the legacy 'DOMNodesInserted'
// event, neither of which exist in the SS6 React asset-admin. This rewrite:
//   * uses a MutationObserver to detect the cropper mount once React renders it,
//   * builds its own <img> + Cropper inside a LiteralField mount (raw HTML, opaque
//     to React, so our DOM is never reconciled away),
//   * is a combined crop + focus widget: the crop handles work natively (nothing is
//     overlaid on top of them); the focus point is set by a plain click inside the
//     crop box (drags and handle clicks are ignored),
//   * writes the crop JSON and focus point back into the React-controlled CropData /
//     FocusPointX / FocusPointY inputs via the native value setter + input event.

function setReactInputValue(input, value) {
  if (!input) {
    return;
  }
  const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
  if (setter && setter.set) {
    setter.set.call(input, value);
  } else {
    input.value = value;
  }
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

function field(name) {
  return document.querySelector(`input[name="${name}"]`);
}

function clamp(v) {
  return Math.max(-1, Math.min(1, v));
}

// No overlay (so the crop handles stay native & fully grabbable). A plain click
// inside the crop box sets the focus point; clicks on a handle or drags are ignored.
function buildFocusPicker(mount, cropper) {
  const container = mount.querySelector('.cropper-container');
  if (!container || mount.querySelector('.sscropper-focus__marker')) {
    return;
  }

  const marker = document.createElement('span');
  marker.className = 'sscropper-focus__marker';
  container.appendChild(marker);

  const positionMarker = (focusX, focusY) => {
    marker.style.left = `${(focusX + 1) * 0.5 * container.clientWidth}px`;
    marker.style.top = `${(focusY + 1) * 0.5 * container.clientHeight}px`;
  };

  let fx = parseFloat((field('FocusPointX') || {}).value);
  let fy = parseFloat((field('FocusPointY') || {}).value);
  if (Number.isNaN(fx)) { fx = 0; }
  if (Number.isNaN(fy)) { fy = 0; }
  positionMarker(fx, fy);

  // Record the press position in the capture phase (before cropper handles it) so the
  // drag detection below is reliable regardless of pointer/mouse event interplay.
  let downX = null;
  let downY = null;
  document.addEventListener('pointerdown', (event) => {
    downX = event.clientX;
    downY = event.clientY;
  }, true);

  container.addEventListener('click', (event) => {
    // ignore clicks that were actually drags (e.g. resizing the crop box)
    if (downX !== null && (Math.abs(event.clientX - downX) > 4 || Math.abs(event.clientY - downY) > 4)) {
      return;
    }
    // ignore clicks on the crop handles/lines
    if (event.target.closest && event.target.closest('.cropper-point, .cropper-line')) {
      return;
    }
    const rect = container.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;
    // the focus point may only be placed inside the current crop rectangle
    if (cropper && typeof cropper.getCropBoxData === 'function') {
      const box = cropper.getCropBoxData();
      if (x < box.left || x > box.left + box.width || y < box.top || y > box.top + box.height) {
        return;
      }
    }
    const focusX = clamp((x * 2 / rect.width) - 1);
    const focusY = clamp((y * 2 / rect.height) - 1);
    positionMarker(focusX, focusY);
    setReactInputValue(field('FocusPointX'), String(focusX));
    setReactInputValue(field('FocusPointY'), String(focusY));
  });
}

function initCropper(mount) {
  if (mount.getAttribute('data-sscropper-init') === '1') {
    return;
  }
  mount.setAttribute('data-sscropper-init', '1');

  let cropConfig = {};
  let cropSizing = {};
  try { cropConfig = JSON.parse(mount.getAttribute('data-cropconfig')) || {}; } catch (e) { cropConfig = {}; }
  try { cropSizing = JSON.parse(mount.getAttribute('data-cropsizing')) || {}; } catch (e) { cropSizing = {}; }

  const imageUrl = mount.getAttribute('data-imageurl');
  const imageWidth = parseInt(mount.getAttribute('data-imagewidth'), 10) || 0;
  const imageHeight = parseInt(mount.getAttribute('data-imageheight'), 10) || 0;
  if (!imageUrl) {
    mount.removeAttribute('data-sscropper-init');
    return;
  }

  const img = document.createElement('img');
  img.className = 'sscropper__image';
  if (imageWidth) { img.width = imageWidth; }
  if (imageHeight) { img.height = imageHeight; }
  img.src = imageUrl;
  mount.appendChild(img);

  const baseConfig = {
    viewMode: 1,
    autoCropArea: 1,
    movable: false,
    rotatable: false,
    scalable: false,
    zoomable: false,
    zoomOnTouch: false,
    zoomOnWheel: false,
    dragMode: 'none',
    guides: false,
    center: false,
    highlight: false,
    background: false,
    toggleDragModeOnDblclick: false,
  };
  const mergedConfig = Object.assign({}, baseConfig, cropConfig);

  let cropDataToOriginalScale = 1;

  mergedConfig.ready = function () {
    const cropper = this.cropper;
    const imgData = cropper.getImageData();
    const previewW = cropSizing.previewWidth || imgData.naturalWidth;
    const originalW = cropSizing.originalWidth || imgData.naturalWidth;
    cropDataToOriginalScale = (imgData.width / imgData.naturalWidth) * (originalW / previewW);
    const originalToCropDataScale = cropDataToOriginalScale ? (1 / cropDataToOriginalScale) : 1;

    const cropField = field('CropData');
    if (cropField && cropField.value) {
      try {
        const existing = JSON.parse(cropField.value);
        if (existing && typeof existing.originalX !== 'undefined') {
          cropper.setData({
            x: existing.originalX * originalToCropDataScale,
            y: existing.originalY * originalToCropDataScale,
            width: existing.originalWidth * originalToCropDataScale,
            height: existing.originalHeight * originalToCropDataScale,
          });
        }
      } catch (e) {
        // no/invalid existing crop -> leave default crop box
      }
    }

    buildFocusPicker(mount, cropper);
  };

  mergedConfig.cropend = function () {
    const cropData = this.cropper.getData(true);
    cropData.originalX = Math.round(cropData.x * cropDataToOriginalScale);
    cropData.originalY = Math.round(cropData.y * cropDataToOriginalScale);
    cropData.originalWidth = Math.round(cropData.width * cropDataToOriginalScale);
    cropData.originalHeight = Math.round(cropData.height * cropDataToOriginalScale);
    setReactInputValue(field('CropData'), JSON.stringify(cropData));
  };

  const start = () => {
    // eslint-disable-next-line no-new
    new Cropper(img, mergedConfig);
  };
  if (img.complete && img.naturalWidth) {
    start();
  } else {
    img.addEventListener('load', start, { once: true });
    img.addEventListener('error', () => mount.removeAttribute('data-sscropper-init'), { once: true });
  }
}

function scanForCroppers() {
  document.querySelectorAll('.sscropper:not([data-sscropper-init])').forEach(initCropper);
}

if (typeof MutationObserver !== 'undefined') {
  const observer = new MutationObserver(() => scanForCroppers());
  observer.observe(document.documentElement, { childList: true, subtree: true });
}
document.addEventListener('DOMContentLoaded', scanForCroppers);
scanForCroppers();
