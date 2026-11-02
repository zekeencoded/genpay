/* Square crop for profile photos (student, parent and merchant profile pages).
 * Needs Bootstrap's JS and profile_hub.css (.ph-crop-* styles).
 *
 * Usage
 *   const photo = await gpCropPhoto(file);   // null when cancelled
 *   if (photo) fd.append('photo', photo, 'photo.jpg');
 *
 * Drag to move; pinch, mouse wheel or the slider to zoom. The result is a
 * 512px JPEG, well under api/profile_photo.php's 2 MB cap. A file the browser
 * can't decode is returned as-is, so the server's own error still shows.
 */
(function () {
    'use strict';

    var OUT = 512;        // output size in px
    var MAX_ZOOM = 4;

    var modalEl, modal, stage, canvas, ctx, zoomInput;
    var img, size = 0, zoom = 1, cx = 0, cy = 0, result = null, done = null;
    var pointers = {}, pinchStart = 0, pinchZoom = 1;

    function build() {
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade ph-sheet';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-labelledby', 'phCropTitle');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
              '<div class="modal-content">' +
                '<div class="modal-header">' +
                  '<h2 class="modal-title" id="phCropTitle">Crop your photo</h2>' +
                  '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>' +
                '</div>' +
                '<div class="ph-crop-body">' +
                  '<div class="ph-crop-stage"><canvas></canvas></div>' +
                  '<label class="ph-crop-zoom">' +
                    '<i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i>' +
                    '<input type="range" min="1" max="' + MAX_ZOOM + '" step="0.01" value="1" aria-label="Zoom">' +
                    '<i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i>' +
                  '</label>' +
                  '<p class="ph-crop-hint">Drag to move the photo. Pinch or use the slider to zoom.</p>' +
                '</div>' +
                '<div class="ph-crop-actions">' +
                  '<button type="button" class="ph-crop-cancel" data-bs-dismiss="modal">Cancel</button>' +
                  '<button type="button" class="ph-btn-gold ph-crop-save">Use photo</button>' +
                '</div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modalEl);

        stage = modalEl.querySelector('.ph-crop-stage');
        canvas = stage.querySelector('canvas');
        ctx = canvas.getContext('2d');
        zoomInput = modalEl.querySelector('input[type="range"]');
        modal = new bootstrap.Modal(modalEl);

        modalEl.addEventListener('shown.bs.modal', function () { measure(true); });
        modalEl.addEventListener('hidden.bs.modal', function () {
            URL.revokeObjectURL(img.src);
            var cb = done;
            done = null;
            if (cb) cb(result);
        });
        window.addEventListener('resize', function () {
            if (done) measure(false);
        });

        zoomInput.addEventListener('input', function () { setZoom(+zoomInput.value); });
        stage.addEventListener('wheel', function (e) {
            e.preventDefault();
            setZoom(zoom * Math.exp(-e.deltaY * 0.0015));
        }, { passive: false });

        stage.addEventListener('pointerdown', function (e) {
            stage.setPointerCapture(e.pointerId);
            pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            if (Object.keys(pointers).length === 2) {
                pinchStart = pinchDistance();
                pinchZoom = zoom;
            }
        });
        stage.addEventListener('pointermove', function (e) {
            var p = pointers[e.pointerId];
            if (!p) return;
            var ids = Object.keys(pointers);
            if (ids.length === 1) {
                cx += e.clientX - p.x;
                cy += e.clientY - p.y;
            }
            p.x = e.clientX;
            p.y = e.clientY;
            if (ids.length === 2 && pinchStart > 0) {
                setZoom(pinchZoom * pinchDistance() / pinchStart);
                return;
            }
            clampAndDraw();
        });
        ['pointerup', 'pointercancel'].forEach(function (type) {
            stage.addEventListener(type, function (e) {
                delete pointers[e.pointerId];
                pinchStart = 0;
            });
        });

        modalEl.querySelector('.ph-crop-save').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            var out = document.createElement('canvas');
            out.width = out.height = OUT;
            var octx = out.getContext('2d');
            var k = OUT / size;
            var s = coverScale() * zoom * k;
            octx.fillStyle = '#fff'; // JPEG has no transparency
            octx.fillRect(0, 0, OUT, OUT);
            octx.drawImage(img, cx * k - img.naturalWidth * s / 2, cy * k - img.naturalHeight * s / 2,
                img.naturalWidth * s, img.naturalHeight * s);
            out.toBlob(function (blob) {
                result = blob;
                btn.disabled = false;
                modal.hide();
            }, 'image/jpeg', 0.9);
        });
    }

    function pinchDistance() {
        var p = Object.keys(pointers).map(function (id) { return pointers[id]; });
        return Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
    }

    // Scale at which the image just covers the square (zoom 1).
    function coverScale() {
        return Math.max(size / img.naturalWidth, size / img.naturalHeight);
    }

    function measure(reset) {
        var old = size;
        size = stage.clientWidth;
        var dpr = window.devicePixelRatio || 1;
        canvas.width = canvas.height = Math.round(size * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        if (reset || !old) {
            zoom = 1;
            zoomInput.value = 1;
            cx = cy = size / 2;
        } else {
            cx *= size / old;
            cy *= size / old;
        }
        clampAndDraw();
    }

    // Zoom around the middle of the square.
    function setZoom(z) {
        z = Math.min(MAX_ZOOM, Math.max(1, z));
        cx = size / 2 + (cx - size / 2) * z / zoom;
        cy = size / 2 + (cy - size / 2) * z / zoom;
        zoom = z;
        zoomInput.value = z;
        clampAndDraw();
    }

    // Keep the square fully covered, then repaint.
    function clampAndDraw() {
        var s = coverScale() * zoom;
        var hw = img.naturalWidth * s / 2;
        var hh = img.naturalHeight * s / 2;
        cx = Math.min(hw, Math.max(size - hw, cx));
        cy = Math.min(hh, Math.max(size - hh, cy));
        ctx.clearRect(0, 0, size, size);
        ctx.drawImage(img, cx - hw, cy - hh, hw * 2, hh * 2);
    }

    window.gpCropPhoto = function (file) {
        return new Promise(function (resolve) {
            if (!file) return resolve(null);
            var probe = new Image();
            probe.onload = function () {
                if (!modalEl) build();
                img = probe;
                result = null;
                pointers = {};
                done = resolve;
                modal.show();
            };
            probe.onerror = function () {
                URL.revokeObjectURL(probe.src);
                resolve(file);
            };
            probe.src = URL.createObjectURL(file);
        });
    };
})();
