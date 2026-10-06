(function () {
  "use strict";

  const app = document.querySelector("[data-tme-app]");
  if (!app || typeof TMEPublic === "undefined") return;

  const q = (selector) => app.querySelector(selector);
  const formView = q("[data-tme-form-view]");
  const choiceView = q("[data-tme-choice-view]");
  const photoView = q("[data-tme-photo-view]");
  const recorderView = q("[data-tme-recorder-view]");
  const doneView = q("[data-tme-done-view]");
  const form = q("[data-tme-form]");
  const formSubmit = q("[data-tme-form-submit]");
  const choosePhotosButton = q("[data-tme-choose-photos]");
  const chooseVideoButton = q("[data-tme-choose-video]");
  const alertBox = q("[data-tme-alert]");
  const permission = q("[data-tme-permission]");
  const recorderShell = q("[data-tme-recorder]");
  const video = q("[data-tme-video]");
  const enableButton = q("[data-tme-enable]");
  const switchButton = q("[data-tme-switch]");
  const startButton = q("[data-tme-start]");
  const stopButton = q("[data-tme-stop]");
  const exitFullscreenButton = q("[data-tme-exit-fullscreen]");
  const retakeButton = q("[data-tme-retake]");
  const submitButton = q("[data-tme-submit]");
  const fileInput = q("[data-tme-file]");
  const recPill = q("[data-tme-rec-pill]");
  const clock = q("[data-tme-clock]");
  const help = q("[data-tme-help]");
  const progress = q("[data-tme-progress]");
  const progressBar = q("[data-tme-progress-bar]");
  const progressText = q("[data-tme-progress-text]");
  const photoFiles = q("[data-tme-photo-files]");
  const photoCamera = q("[data-tme-photo-camera]");
  const photoGrid = q("[data-tme-photo-grid]");
  const photoEmpty = q("[data-tme-photo-empty]");
  const photoCount = q("[data-tme-photo-count]");
  const photoSubmit = q("[data-tme-submit-photos]");
  const photoProgress = q("[data-tme-photo-progress]");
  const photoProgressBar = q("[data-tme-photo-progress-bar]");
  const photoProgressText = q("[data-tme-photo-progress-text]");

  let token = TMEPublic.initial ? TMEPublic.initial.token : "";
  let stream = null;
  let mediaRecorder = null;
  let chunks = [];
  let selectedBlob = null;
  let selectedUrl = "";
  let selectedPhotos = [];
  let facingMode = "environment";
  let seconds = 0;
  let clockTimer = null;
  let limitTimer = null;
  let busy = false;
  let recorderPlaceholder = null;

  function show(element, visible) {
    if (element) element.hidden = !visible;
  }

  function message(text) {
    alertBox.textContent = text || "";
    show(alertBox, Boolean(text));
    if (text) alertBox.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function timeLabel(value) {
    return Math.floor(value / 60) + ":" + String(value % 60).padStart(2, "0");
  }

  function uniqueId(prefix) {
    if (window.crypto && crypto.randomUUID) return prefix + crypto.randomUUID().replace(/-/g, "");
    return prefix + Date.now().toString(36) + Math.random().toString(36).slice(2);
  }

  async function api(path, options) {
    const response = await fetch(TMEPublic.restUrl + path, Object.assign({
      headers: { "Content-Type": "application/json" },
    }, options || {}));
    let data = {};
    try { data = await response.json(); } catch (_) { data = {}; }
    if (!response.ok) throw new Error(data.message || "Something went wrong. Please try again.");
    return data;
  }

  function uploadToR2(url, blob, contentType, onProgress, label) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("PUT", url, true);
      xhr.setRequestHeader("Content-Type", contentType);
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable && typeof onProgress === "function") {
          onProgress(event.loaded, event.total);
        }
      };
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) resolve();
        else reject(new Error((label || "File") + " upload failed (" + xhr.status + "). Please try again."));
      };
      xhr.onerror = () => reject(new Error("The browser could not reach private media storage. Check your connection and try again."));
      xhr.send(blob);
    });
  }

  function doneCopy(type) {
    if (type === "photos") return "Your photos were uploaded securely. Tom Moving will review them and follow up with you.";
    if (type === "video") return "Your video walkthrough was uploaded securely. Tom Moving will review it and follow up with you.";
    return "Your moving information was received. Tom Moving will review it and follow up with you.";
  }

  function openDone(clientName, type) {
    exitRecordingView();
    stopTracks();
    clearTimers();
    show(formView, false);
    show(choiceView, false);
    show(photoView, false);
    show(recorderView, false);
    show(doneView, true);
    const heading = doneView.querySelector("h2");
    const copy = doneView.querySelector("[data-tme-done-message]");
    if (heading && clientName) heading.textContent = "Thank you, " + clientName + "!";
    if (copy) copy.textContent = doneCopy(type);
    doneView.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function openChoice(clientName) {
    q("[data-tme-choice-client-name]").textContent = clientName || "there";
    show(formView, false);
    show(choiceView, true);
    show(photoView, false);
    show(recorderView, false);
    show(doneView, false);
    choiceView.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function openPhotos(clientName) {
    q("[data-tme-photo-client-name]").textContent = clientName || "there";
    show(formView, false);
    show(choiceView, false);
    show(recorderView, false);
    show(doneView, false);
    show(photoView, true);
    renderPhotos();
    photoView.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function openRecorder(clientName) {
    q("[data-tme-client-name]").textContent = clientName || "there";
    show(formView, false);
    show(choiceView, false);
    show(photoView, false);
    show(doneView, false);
    show(recorderView, true);
    recorderView.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  async function chooseSubmissionType(type) {
    if (busy || !token || !["photos", "video"].includes(type)) return;
    busy = true;
    message("");
    choosePhotosButton.disabled = true;
    chooseVideoButton.disabled = true;
    try {
      const result = await api("sessions/" + token + "/submission-type", {
        method: "POST",
        body: JSON.stringify({ submissionType: type }),
      });
      const clientName = result.clientName || q("[data-tme-choice-client-name]").textContent;
      if (result.submissionType === "photos") openPhotos(clientName);
      else openRecorder(clientName);
    } catch (error) {
      message(error && error.message ? error.message : "The media walkthrough could not be started. Please try again.");
    } finally {
      busy = false;
      choosePhotosButton.disabled = false;
      chooseVideoButton.disabled = false;
    }
  }

  function photoContentType(file) {
    const direct = String(file.type || "").toLowerCase().split(";")[0];
    if (direct === "image/jpg") return "image/jpeg";
    if (["image/jpeg", "image/png", "image/webp", "image/heic", "image/heif"].includes(direct)) return direct;
    const extension = String(file.name || "").toLowerCase().split(".").pop();
    return {
      jpg: "image/jpeg",
      jpeg: "image/jpeg",
      png: "image/png",
      webp: "image/webp",
      heic: "image/heic",
      heif: "image/heif",
    }[extension] || "";
  }

  function addPhotos(files) {
    message("");
    const incoming = Array.from(files || []);
    for (const file of incoming) {
      if (selectedPhotos.length >= TMEPublic.maxPhotos) {
        message("You can submit up to " + TMEPublic.maxPhotos + " photos.");
        break;
      }
      const contentType = photoContentType(file);
      if (!contentType) {
        message("Please choose JPEG, PNG, WebP, HEIC or HEIF photos.");
        continue;
      }
      if (file.size < 1 || file.size > TMEPublic.maxPhotoBytes) {
        message("Each photo must be smaller than " + TMEPublic.maxPhotoMb + " MB.");
        continue;
      }
      selectedPhotos.push({
        clientId: uniqueId("p_"),
        file: file,
        contentType: contentType,
        url: URL.createObjectURL(file),
      });
    }
    if (photoFiles) photoFiles.value = "";
    if (photoCamera) photoCamera.value = "";
    renderPhotos();
  }

  function removePhoto(clientId) {
    const target = selectedPhotos.find((photo) => photo.clientId === clientId);
    if (target && target.url) URL.revokeObjectURL(target.url);
    selectedPhotos = selectedPhotos.filter((photo) => photo.clientId !== clientId);
    renderPhotos();
  }

  function renderPhotos() {
    photoGrid.textContent = "";
    selectedPhotos.forEach((photo, index) => {
      const card = document.createElement("article");
      card.className = "tme-photo-card";
      const image = document.createElement("img");
      image.src = photo.url;
      image.alt = "Selected photo " + (index + 1);
      image.addEventListener("error", function () { image.hidden = true; });
      const details = document.createElement("div");
      const name = document.createElement("strong");
      name.textContent = photo.file.name || "Photo " + (index + 1);
      const size = document.createElement("small");
      size.textContent = Math.max(1, Math.round(photo.file.size / 1024)) + " KB";
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "tme-photo-remove";
      remove.textContent = "Remove";
      remove.setAttribute("aria-label", "Remove " + name.textContent);
      remove.addEventListener("click", () => removePhoto(photo.clientId));
      details.append(name, size);
      card.append(image, details, remove);
      photoGrid.appendChild(card);
    });
    photoCount.textContent = selectedPhotos.length + " of " + TMEPublic.maxPhotos + " photos";
    show(photoEmpty, selectedPhotos.length === 0);
    photoSubmit.disabled = busy || selectedPhotos.length === 0;
  }

  async function submitPhotos() {
    if (!token || !selectedPhotos.length || busy) return;
    busy = true;
    message("");
    photoSubmit.disabled = true;
    show(photoProgress, true);
    show(photoProgressText, true);
    photoProgressBar.style.width = "0%";
    const uploaded = [];
    try {
      for (let index = 0; index < selectedPhotos.length; index += 1) {
        const photo = selectedPhotos[index];
        photoProgressText.textContent = "Preparing photo " + (index + 1) + " of " + selectedPhotos.length + "...";
        const signed = await api("sessions/" + token + "/photo-upload-url", {
          method: "POST",
          body: JSON.stringify({
            clientId: photo.clientId,
            contentType: photo.contentType,
            size: photo.file.size,
            name: photo.file.name,
          }),
        });
        await uploadToR2(signed.uploadUrl, photo.file, photo.contentType, function (loaded, total) {
          const fileFraction = total ? loaded / total : 0;
          const overall = Math.round(((index + fileFraction) / selectedPhotos.length) * 100);
          photoProgressBar.style.width = Math.min(100, overall) + "%";
          photoProgressText.textContent = "Uploading photo " + (index + 1) + " of " + selectedPhotos.length + "... " + Math.round(fileFraction * 100) + "%";
        }, "Photo");
        uploaded.push({ id: signed.id, key: signed.key });
      }
      photoProgressText.textContent = "Verifying photos...";
      await api("sessions/" + token + "/complete-photos", {
        method: "POST",
        body: JSON.stringify({ photos: uploaded }),
      });
      const name = q("[data-tme-photo-client-name]").textContent;
      selectedPhotos.forEach((photo) => { if (photo.url) URL.revokeObjectURL(photo.url); });
      selectedPhotos = [];
      openDone(name, "photos");
    } catch (error) {
      message(error && error.message ? error.message : "The photos could not be submitted. Please try again.");
    } finally {
      busy = false;
      photoSubmit.disabled = selectedPhotos.length === 0;
      show(photoProgress, false);
      show(photoProgressText, false);
    }
  }

  function mountRecorderAtViewportRoot() {
    if (recorderShell.parentNode === document.body) return;
    recorderPlaceholder = document.createComment("tme-recorder-placeholder");
    recorderShell.parentNode.insertBefore(recorderPlaceholder, recorderShell);
    document.body.appendChild(recorderShell);
  }

  function restoreRecorderMount() {
    if (recorderPlaceholder && recorderPlaceholder.parentNode) {
      recorderPlaceholder.parentNode.insertBefore(recorderShell, recorderPlaceholder);
      recorderPlaceholder.parentNode.removeChild(recorderPlaceholder);
    }
    recorderPlaceholder = null;
  }

  function enterRecordingView() {
    mountRecorderAtViewportRoot();
    recorderShell.classList.add("tme-recorder--immersive");
    document.body.classList.add("tme-recorder-active");
    document.documentElement.classList.add("tme-recorder-active");
    show(exitFullscreenButton, true);
    const requestFullscreen = recorderShell.requestFullscreen || recorderShell.webkitRequestFullscreen;
    if (typeof requestFullscreen === "function") {
      try {
        const result = requestFullscreen.call(recorderShell, { navigationUI: "hide" });
        if (result && typeof result.catch === "function") result.catch(() => undefined);
      } catch (_) {
        // CSS full-viewport recording remains active.
      }
    }
  }

  function exitRecordingView() {
    if (!recorderShell) return;
    const fullscreenElement = document.fullscreenElement || document.webkitFullscreenElement;
    const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
    if (fullscreenElement && typeof exitFullscreen === "function") {
      try {
        const result = exitFullscreen.call(document);
        if (result && typeof result.catch === "function") result.catch(() => undefined);
      } catch (_) {
        // CSS full-viewport recording can still be closed.
      }
    }
    show(exitFullscreenButton, false);
    restoreRecorderMount();
    recorderShell.classList.remove("tme-recorder--immersive");
    document.body.classList.remove("tme-recorder-active");
    document.documentElement.classList.remove("tme-recorder-active");
  }

  function stopTracks() {
    if (stream) stream.getTracks().forEach((track) => track.stop());
    stream = null;
  }

  function clearTimers() {
    if (clockTimer) window.clearInterval(clockTimer);
    if (limitTimer) window.clearTimeout(limitTimer);
    clockTimer = null;
    limitTimer = null;
  }

  function clearSelected() {
    if (selectedUrl) URL.revokeObjectURL(selectedUrl);
    selectedUrl = "";
    selectedBlob = null;
  }

  function supportedMime() {
    if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== "function") return "";
    const options = [
      "video/mp4;codecs=h264,aac",
      "video/mp4",
      "video/webm;codecs=vp9,opus",
      "video/webm;codecs=vp8,opus",
      "video/webm",
    ];
    return options.find((type) => MediaRecorder.isTypeSupported(type)) || "";
  }

  function previewState() {
    exitRecordingView();
    show(permission, false);
    show(recorderShell, true);
    show(startButton, true);
    show(stopButton, false);
    show(retakeButton, false);
    show(submitButton, false);
    show(switchButton, true);
    show(recPill, false);
    show(progress, false);
    show(progressText, false);
    video.controls = false;
    video.muted = true;
    video.autoplay = true;
    help.textContent = "Hold your phone horizontally when possible, then walk at a comfortable pace.";
  }

  function reviewState() {
    exitRecordingView();
    show(startButton, false);
    show(stopButton, false);
    show(retakeButton, true);
    show(submitButton, true);
    show(switchButton, false);
    show(recPill, false);
    video.controls = true;
    video.muted = false;
    video.autoplay = false;
    help.textContent = "Review your walkthrough before sending it.";
  }

  async function enableCamera(nextFacing) {
    message("");
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      message("This browser cannot record directly. Choose an existing video instead, or use the current Safari, Chrome or Edge browser.");
      return;
    }
    try {
      stopTracks();
      facingMode = nextFacing || facingMode;
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: facingMode }, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: true,
      });
      video.srcObject = stream;
      await video.play().catch(() => undefined);
      previewState();
    } catch (_) {
      message("Camera or microphone access was blocked. Allow access in your browser settings, then try again. You can also choose an existing video.");
    }
  }

  function stopRecording() {
    exitRecordingView();
    if (mediaRecorder && mediaRecorder.state === "recording") mediaRecorder.stop();
    clearTimers();
    stopTracks();
  }

  function beginRecording() {
    if (!stream || busy) return;
    enterRecordingView();
    message("");
    clearSelected();
    chunks = [];
    seconds = 0;
    clock.textContent = "0:00";
    try {
      const mimeType = supportedMime();
      const options = { videoBitsPerSecond: 1250000, audioBitsPerSecond: 64000 };
      if (mimeType) options.mimeType = mimeType;
      mediaRecorder = new MediaRecorder(stream, options);
      mediaRecorder.ondataavailable = (event) => { if (event.data && event.data.size) chunks.push(event.data); };
      mediaRecorder.onerror = () => {
        exitRecordingView();
        message("Recording stopped unexpectedly. Please try again.");
        stopTracks();
      };
      mediaRecorder.onstop = () => {
        selectedBlob = new Blob(chunks, { type: mediaRecorder.mimeType || mimeType || "video/webm" });
        if (!selectedBlob.size) {
          message("No video was captured. Please try again.");
          show(permission, true);
          show(recorderShell, false);
          return;
        }
        if (selectedBlob.size > TMEPublic.maxBytes) {
          message("This recording is larger than " + TMEPublic.maxMb + " MB. Please retake a shorter walkthrough.");
          clearSelected();
          show(permission, true);
          show(recorderShell, false);
          return;
        }
        selectedUrl = URL.createObjectURL(selectedBlob);
        video.srcObject = null;
        video.src = selectedUrl;
        reviewState();
      };
      mediaRecorder.start(1000);
      show(startButton, false);
      show(stopButton, true);
      show(switchButton, false);
      show(recPill, true);
      help.textContent = "Recording " + timeLabel(seconds) + " - maximum 15:00";
      clockTimer = window.setInterval(() => {
        seconds += 1;
        clock.textContent = timeLabel(seconds);
        help.textContent = "Recording " + timeLabel(seconds) + " - maximum 15:00";
      }, 1000);
      limitTimer = window.setTimeout(stopRecording, TMEPublic.maxSeconds * 1000);
    } catch (_) {
      exitRecordingView();
      message("This browser could not start the recorder. Try the current Safari, Chrome or Edge browser.");
    }
  }

  async function retake() {
    clearSelected();
    video.removeAttribute("src");
    video.load();
    await enableCamera(facingMode);
  }

  function chooseFile(file) {
    exitRecordingView();
    message("");
    if (!file) return;
    if (file.size > TMEPublic.maxBytes) {
      message("This video is larger than " + TMEPublic.maxMb + " MB. Please choose or record a shorter video.");
      fileInput.value = "";
      return;
    }
    if (!/^video\/(mp4|webm|quicktime)$/i.test(file.type)) {
      message("Please choose an MP4, WebM or QuickTime video.");
      fileInput.value = "";
      return;
    }
    stopTracks();
    clearSelected();
    selectedBlob = file;
    selectedUrl = URL.createObjectURL(file);
    show(permission, false);
    show(recorderShell, true);
    video.srcObject = null;
    video.src = selectedUrl;
    video.onloadedmetadata = function () {
      if (Number.isFinite(video.duration) && video.duration > TMEPublic.maxSeconds + 2) {
        message("The video is longer than 15 minutes. Please choose or record a shorter walkthrough.");
        clearSelected();
        show(permission, true);
        show(recorderShell, false);
      }
    };
    reviewState();
  }

  async function submitVideo() {
    if (!selectedBlob || !token || busy) return;
    busy = true;
    message("");
    submitButton.disabled = true;
    retakeButton.disabled = true;
    show(progress, true);
    show(progressText, true);
    progressBar.style.width = "0%";
    progressText.textContent = "Preparing secure upload...";
    try {
      const contentType = (selectedBlob.type || "video/mp4").split(";")[0].toLowerCase();
      const signed = await api("sessions/" + token + "/upload-url", {
        method: "POST",
        body: JSON.stringify({ contentType: contentType, size: selectedBlob.size }),
      });
      await uploadToR2(signed.uploadUrl, selectedBlob, contentType, function (loaded, total) {
        const percent = total ? Math.min(100, Math.round((loaded / total) * 100)) : 0;
        progressBar.style.width = percent + "%";
        progressText.textContent = "Uploading securely... " + percent + "%";
      }, "Video");
      progressText.textContent = "Verifying upload...";
      await api("sessions/" + token + "/complete", {
        method: "POST",
        body: JSON.stringify({ key: signed.key }),
      });
      const name = q("[data-tme-client-name]").textContent;
      clearSelected();
      openDone(name, "video");
    } catch (error) {
      message(error && error.message ? error.message : "Upload failed. Please try again.");
    } finally {
      busy = false;
      submitButton.disabled = false;
      retakeButton.disabled = false;
      show(progress, false);
      show(progressText, false);
    }
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    if (busy || !form.reportValidity()) return;
    busy = true;
    message("");
    const original = formSubmit.textContent;
    formSubmit.disabled = true;
    formSubmit.textContent = "Submitting...";
    const values = Object.fromEntries(new FormData(form).entries());
    values.consent = Boolean(values.consent);
    values.submissionType = "info";
    try {
      const result = await api("sessions", { method: "POST", body: JSON.stringify(values) });
      token = result.token;
      const url = new URL(TMEPublic.estimateUrl, window.location.href);
      url.searchParams.set("session", token);
      window.history.replaceState({}, "", url.toString());
      openChoice(result.clientName || values.clientName);
    } catch (error) {
      message(error && error.message ? error.message : "The estimate could not be started. Please try again.");
    } finally {
      busy = false;
      formSubmit.disabled = false;
      formSubmit.textContent = original;
    }
  });

  choosePhotosButton.addEventListener("click", () => chooseSubmissionType("photos"));
  chooseVideoButton.addEventListener("click", () => chooseSubmissionType("video"));
  if (photoFiles) photoFiles.addEventListener("change", () => addPhotos(photoFiles.files));
  if (photoCamera) photoCamera.addEventListener("change", () => addPhotos(photoCamera.files));
  if (photoSubmit) photoSubmit.addEventListener("click", submitPhotos);
  enableButton.addEventListener("click", () => enableCamera(facingMode));
  switchButton.addEventListener("click", () => enableCamera(facingMode === "environment" ? "user" : "environment"));
  startButton.addEventListener("click", beginRecording);
  stopButton.addEventListener("click", stopRecording);
  if (exitFullscreenButton) exitFullscreenButton.addEventListener("click", exitRecordingView);
  retakeButton.addEventListener("click", retake);
  submitButton.addEventListener("click", submitVideo);
  fileInput.addEventListener("change", () => chooseFile(fileInput.files && fileInput.files[0]));

  window.addEventListener("beforeunload", function (event) {
    if ((mediaRecorder && mediaRecorder.state === "recording") || busy) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  window.addEventListener("pagehide", function () {
    exitRecordingView();
    stopTracks();
    selectedPhotos.forEach((photo) => { if (photo.url) URL.revokeObjectURL(photo.url); });
  });

  if (TMEPublic.initial) {
    if (TMEPublic.initial.submissionType === "info") openChoice(TMEPublic.initial.clientName);
    else if (TMEPublic.initial.completed) openDone(TMEPublic.initial.clientName, TMEPublic.initial.submissionType);
    else if (TMEPublic.initial.submissionType === "photos") openPhotos(TMEPublic.initial.clientName);
    else openRecorder(TMEPublic.initial.clientName);
  }
})();
