document.addEventListener('DOMContentLoaded', () => {
  const trackingInputReference = document.getElementById('tracking-input-reference');
  const trackingInputQrWrapper = document.querySelector('.tracking-input-qr-wrapper');
  const trackingValueQrHidden = document.getElementById('tracking-value-qr');
  const qrScanBtn = document.getElementById('qr-scan-btn');
  const qrScanBtnText = document.getElementById('qr-scan-btn-text');
  const qrModal = document.getElementById('qr-scanner-modal');
  const qrCloseBtn = document.getElementById('qr-scan-close');
  const qrStatus = document.getElementById('qr-scanner-status');
  let html5QrCode = null;
  let qrScanInProgress = false;
  const radioOptions = document.querySelectorAll('input[name="tracking-method"]');
  const hamburgerButton = document.querySelector('.hamburger-menu');
  const menuWrap = document.querySelector('.menu-wrap');
  const menuDropdown = document.querySelector('.menu-dropdown');

  const closeMenu = () => {
    if (!menuWrap || !hamburgerButton || !menuDropdown) return;
    menuWrap.classList.remove('is-open');
    hamburgerButton.setAttribute('aria-expanded', 'false');
    menuDropdown.setAttribute('hidden', 'hidden');
  };

  const openMenu = () => {
    if (!menuWrap || !hamburgerButton || !menuDropdown) return;
    menuWrap.classList.add('is-open');
    hamburgerButton.setAttribute('aria-expanded', 'true');
    menuDropdown.removeAttribute('hidden');
  };

  // Handle tracking method switching
  if (radioOptions.length) {
    const updateInputMethod = (method) => {
      if (method === 'reference') {
        if (trackingInputReference) {
          trackingInputReference.classList.remove('hidden');
          trackingInputReference.style.display = 'block';
        }
        if (trackingInputQrWrapper) {
          trackingInputQrWrapper.classList.add('hidden');
          trackingInputQrWrapper.style.display = 'none';
        }
        if (trackingInputReference) trackingInputReference.disabled = false;
        if (trackingValueQrHidden) trackingValueQrHidden.disabled = true;
      } else {
        if (trackingInputReference) {
          trackingInputReference.classList.add('hidden');
          trackingInputReference.style.display = 'none';
          trackingInputReference.disabled = true;
        }
        if (trackingInputQrWrapper) {
          trackingInputQrWrapper.classList.remove('hidden');
          trackingInputQrWrapper.style.display = 'flex';
        }
        if (trackingValueQrHidden) trackingValueQrHidden.disabled = false;
      }
    };

    // Initialize based on currently selected radio button
    const selectedRadio = document.querySelector('input[name="tracking-method"]:checked');
    if (selectedRadio) {
      updateInputMethod(selectedRadio.value);
    }

    // Add change listeners to all radio options
    radioOptions.forEach((option) => {
      option.addEventListener('change', (e) => {
        updateInputMethod(e.target.value);
        if (trackingInputReference) trackingInputReference.value = '';
        if (trackingValueQrHidden) trackingValueQrHidden.value = '';
        if (qrScanBtnText) qrScanBtnText.textContent = 'Scan QR code';
      });
    });
  }

  if (qrModal) {
    qrModal.classList.add('hidden');
    qrModal.setAttribute('aria-hidden', 'true');
  }

  const setQrStatus = (message) => {
    if (qrStatus) qrStatus.textContent = message || '';
  };

  const stopQrCamera = async () => {
    if (!html5QrCode) return;
    try {
      const state = html5QrCode.getState && html5QrCode.getState();
      if (state === 2 || state === 3) {
        await html5QrCode.stop();
      }
    } catch (error) {
      // Camera may already be stopped.
    }
    try {
      html5QrCode.clear();
    } catch (error) {
      // Reader may already be cleared.
    }
    html5QrCode = null;
  };

  const stopQrScan = async () => {
    await stopQrCamera();
    qrScanInProgress = false;
    if (qrModal) {
      qrModal.classList.add('hidden');
      qrModal.setAttribute('aria-hidden', 'true');
    }
    setQrStatus('');
  };

  const startQrScan = async () => {
    if (!qrModal) return;
    if (qrScanInProgress) return;

    qrScanInProgress = true;
    qrModal.classList.remove('hidden');
    qrModal.setAttribute('aria-hidden', 'false');

    if (typeof Html5Qrcode === 'undefined') {
      setQrStatus('QR scanner failed to load. Check your connection and try again.');
      qrScanInProgress = false;
      return;
    }

    setQrStatus('Starting camera...');
    await stopQrCamera();

    html5QrCode = new Html5Qrcode('qr-reader');
    const config = { fps: 10, qrbox: { width: 220, height: 220 } };
    const onSuccess = async (decodedText) => {
      const value = String(decodedText || '').trim();
      if (trackingValueQrHidden) trackingValueQrHidden.value = value;
      if (qrScanBtnText) qrScanBtnText.textContent = value ? 'Scanned: ' + value : 'Scan QR code';
      await stopQrScan();
      const trackerForm = document.querySelector('.tracker-form');
      if (value && trackerForm) trackerForm.requestSubmit();
    };

    try {
      await html5QrCode.start({ facingMode: 'environment' }, config, onSuccess);
      setQrStatus('Point the camera at the QR code.');
    } catch (envError) {
      try {
        await html5QrCode.start({ facingMode: 'user' }, config, onSuccess);
        setQrStatus('Point the camera at the QR code.');
      } catch (userError) {
        await stopQrCamera();
        setQrStatus('Could not open the camera. Allow camera permission and try again.');
        qrScanInProgress = false;
      }
    }
  };

  if (qrScanBtn) {
    qrScanBtn.addEventListener('click', startQrScan);
  }

  if (qrCloseBtn) {
    qrCloseBtn.addEventListener('click', () => {
      stopQrScan();
    });
  }

  if (qrModal) {
    qrModal.addEventListener('click', (event) => {
      if (event.target.hasAttribute('data-close-qr-scan')) {
        stopQrScan();
      }
    });
  }

  if (hamburgerButton && menuWrap && menuDropdown) {
    hamburgerButton.addEventListener('click', (event) => {
      event.stopPropagation();
      const isOpen = menuWrap.classList.contains('is-open');
      if (isOpen) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    menuWrap.addEventListener('mouseleave', () => {
      closeMenu();
    });

    document.addEventListener('click', (event) => {
      if (!menuWrap.contains(event.target)) {
        closeMenu();
      }
    });
  }
});
