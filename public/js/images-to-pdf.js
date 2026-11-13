/*
 * Meet - combine images into one PDF in the browser (no library, no server work).
 * window.MeetImagesToPdf(files, name) -> Promise<File>  (one A4 portrait page per image, image fitted with a margin)
 */
(function () {
  'use strict';
  var A4 = { w: 595.28, h: 841.89 }, MARGIN = 24, MAX_PX = 1800;

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Cannot read ' + (file.name || 'image'))); };
      img.src = url;
    });
  }

  // Re-encode as JPEG (PDF embeds JPEG directly with DCTDecode), white background for transparent PNGs.
  function toJpeg(img) {
    var scale = Math.min(1, MAX_PX / Math.max(img.naturalWidth, img.naturalHeight));
    var w = Math.max(1, Math.round(img.naturalWidth * scale)), h = Math.max(1, Math.round(img.naturalHeight * scale));
    var c = document.createElement('canvas');
    c.width = w; c.height = h;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);
    var b64 = c.toDataURL('image/jpeg', 0.85).split(',')[1];
    var bin = atob(b64), bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return { bytes: bytes, w: w, h: h };
  }

  function ascii(s) {
    var b = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i) & 0xff;
    return b;
  }

  function build(images) {
    var parts = [], offsets = [], length = 0;
    function push(x) { var b = typeof x === 'string' ? ascii(x) : x; parts.push(b); length += b.length; }
    function obj(n, body) { offsets[n] = length; push(n + ' 0 obj\n'); body(); push('\nendobj\n'); }

    var pageCount = images.length, kids = [];
    // objects: 1 catalog, 2 pages, then per page: page, image, content
    for (var p = 0; p < pageCount; p++) kids.push((3 + p * 3) + ' 0 R');
    push('%PDF-1.4\n%\xe2\xe3\xcf\xd3\n');
    obj(1, function () { push('<< /Type /Catalog /Pages 2 0 R >>'); });
    obj(2, function () { push('<< /Type /Pages /Kids [' + kids.join(' ') + '] /Count ' + pageCount + ' >>'); });
    images.forEach(function (im, idx) {
      var pageN = 3 + idx * 3, imgN = pageN + 1, contN = pageN + 2;
      var boxW = A4.w - 2 * MARGIN, boxH = A4.h - 2 * MARGIN;
      var s = Math.min(boxW / im.w, boxH / im.h);
      var dw = im.w * s, dh = im.h * s, x = (A4.w - dw) / 2, y = (A4.h - dh) / 2;
      obj(pageN, function () {
        push('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + A4.w + ' ' + A4.h + '] /Resources << /XObject << /Im' + idx + ' ' + imgN + ' 0 R >> >> /Contents ' + contN + ' 0 R >>');
      });
      obj(imgN, function () {
        push('<< /Type /XObject /Subtype /Image /Width ' + im.w + ' /Height ' + im.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + im.bytes.length + ' >>\nstream\n');
        push(im.bytes);
        push('\nendstream');
      });
      var content = 'q ' + dw.toFixed(2) + ' 0 0 ' + dh.toFixed(2) + ' ' + x.toFixed(2) + ' ' + y.toFixed(2) + ' cm /Im' + idx + ' Do Q';
      obj(contN, function () { push('<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream'); });
    });
    var total = 2 + pageCount * 3, xref = length;
    var x = 'xref\n0 ' + (total + 1) + '\n0000000000 65535 f \n';
    for (var n = 1; n <= total; n++) x += String(offsets[n]).padStart(10, '0') + ' 00000 n \n';
    push(x + 'trailer\n<< /Size ' + (total + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');
    return new Blob(parts, { type: 'application/pdf' });
  }

  window.MeetImagesToPdf = function (files, name) {
    return files.reduce(function (chain, f) {
      return chain.then(function (list) { return loadImage(f).then(function (img) { list.push(toJpeg(img)); return list; }); });
    }, Promise.resolve([])).then(function (images) {
      return new File([build(images)], name || 'images.pdf', { type: 'application/pdf' });
    });
  };
})();
