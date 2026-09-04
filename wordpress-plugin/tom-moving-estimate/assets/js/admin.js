(function () {
  "use strict";

  const form = document.querySelector("[data-tme-review-form]");
  if (!form) return;

  const video = form.querySelector("[data-tme-video]");
  const canvas = form.querySelector("[data-tme-canvas]");
  const wrap = form.querySelector("[data-tme-video-wrap]");
  const hidden = form.querySelector("[data-tme-annotations]");
  const list = form.querySelector("[data-tme-annotation-list]");
  const tools = form.querySelector("[data-tme-tools]");
  const drawOptions = form.querySelector("[data-draw-options]");
  const deleteLinks = document.querySelectorAll("[data-tme-delete]");

  deleteLinks.forEach(function (deleteLink) {
    deleteLink.addEventListener("click", function (event) {
      const label = deleteLink.dataset.tmeDeleteLabel || "media";
      if (!window.confirm("Delete " + label + " now? This cannot be undone.")) event.preventDefault();
    });
  });
  if (!video || !canvas || !wrap || !hidden || !tools) return;

  let annotations = [];
  try {
    const parsed = JSON.parse(hidden.value || "[]");
    annotations = Array.isArray(parsed) ? parsed : [];
  } catch (_) { annotations = []; }

  let tool = "laser";
  let color = "#ef4444";
  let lineSize = 3;
  let drawing = false;
  let currentPoints = [];
  let laser = null;
  let dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));

  function id() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return "a_" + Date.now().toString(36) + Math.random().toString(36).slice(2);
  }

  function timeLabel(value) {
    const seconds = Math.max(0, Math.floor(Number(value) || 0));
    return Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0");
  }

  function point(event) {
    const rect = canvas.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100)),
      y: Math.max(0, Math.min(100, ((event.clientY - rect.top) / rect.height) * 100)),
    };
  }

  function resize() {
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(1, Math.round(rect.width * dpr));
    const height = Math.max(1, Math.round(rect.height * dpr));
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width;
      canvas.height = height;
    }
    draw();
  }

  function drawStroke(ctx, item) {
    if (!item.points || item.points.length < 2) return;
    ctx.strokeStyle = item.color || "#ef4444";
    ctx.lineWidth = (Number(item.size) || 3) * dpr;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.beginPath();
    item.points.forEach(function (p, index) {
      const x = (p.x / 100) * canvas.width;
      const y = (p.y / 100) * canvas.height;
      if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    });
    ctx.stroke();
  }

  function drawNote(ctx, item) {
    const x = (item.x / 100) * canvas.width;
    const y = (item.y / 100) * canvas.height;
    const radius = 10 * dpr;
    ctx.fillStyle = "#2563eb";
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = "#fff";
    ctx.font = "bold " + (11 * dpr) + "px sans-serif";
    ctx.textAlign = "center";
    ctx.fillText("N", x, y + (4 * dpr));
    const label = String(item.text || "");
    ctx.font = (12 * dpr) + "px sans-serif";
    const boxWidth = Math.min(canvas.width * .55, Math.max(90 * dpr, ctx.measureText(label).width + 18 * dpr));
    const boxX = Math.min(canvas.width - boxWidth - 4 * dpr, x + 14 * dpr);
    const boxY = Math.max(3 * dpr, y - 13 * dpr);
    ctx.fillStyle = "rgba(15, 23, 42, .88)";
    ctx.fillRect(boxX, boxY, boxWidth, 26 * dpr);
    ctx.fillStyle = "#fff";
    ctx.textAlign = "left";
    ctx.fillText(label.slice(0, 80), boxX + 8 * dpr, boxY + 17 * dpr);
  }

  function drawLaserDot(ctx, xPct, yPct) {
    const x = (xPct / 100) * canvas.width;
    const y = (yPct / 100) * canvas.height;
    ctx.save();
    ctx.shadowColor = "#ff2720";
    ctx.shadowBlur = 18 * dpr;
    ctx.fillStyle = "#ff2720";
    ctx.beginPath();
    ctx.arc(x, y, 6 * dpr, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }

  function draw() {
    const ctx = canvas.getContext("2d");
    if (!ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const now = Number(video.currentTime) || 0;
    annotations.forEach(function (item) {
      if (Math.abs(Number(item.time) - now) > .75) return;
      if (item.type === "draw") drawStroke(ctx, item);
      if (item.type === "note") drawNote(ctx, item);
      if (item.type === "laser") drawLaserDot(ctx, item.x, item.y);
    });
    if (drawing && currentPoints.length > 1) {
      drawStroke(ctx, { points: currentPoints, color: color, size: lineSize });
    }
    if (laser) drawLaserDot(ctx, laser.x, laser.y);
  }

  function sync() {
    hidden.value = JSON.stringify(annotations);
    renderList();
    draw();
  }

  function renderList() {
    if (!list) return;
    list.textContent = "";
    if (!annotations.length) {
      const empty = document.createElement("p");
      empty.className = "description";
      empty.textContent = "No saved drawings, notes or laser points yet.";
      list.appendChild(empty);
      return;
    }
    annotations.slice().sort((a, b) => a.time - b.time).forEach(function (item) {
      const row = document.createElement("div");
      const seek = document.createElement("button");
      seek.type = "button";
      seek.className = "button button-small";
      seek.textContent = timeLabel(item.time);
      seek.addEventListener("click", function () {
        video.currentTime = Number(item.time) || 0;
        video.pause();
        draw();
      });
      const label = document.createElement("span");
      label.textContent = item.type === "note" ? item.text : (item.type === "laser" ? "Laser point" : "Drawing");
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "button-link-delete";
      remove.textContent = "Remove";
      remove.addEventListener("click", function () {
        annotations = annotations.filter((candidate) => candidate.id !== item.id);
        sync();
      });
      row.append(seek, label, remove);
      list.appendChild(row);
    });
  }

  function setTool(next) {
    tool = next;
    laser = null;
    drawing = false;
    currentPoints = [];
    tools.querySelectorAll("[data-tool]").forEach(function (button) {
      button.classList.toggle("is-active", button.dataset.tool === tool);
    });
    drawOptions.hidden = tool !== "draw";
    canvas.style.cursor = tool === "laser" ? "none" : "crosshair";
    draw();
  }

  canvas.addEventListener("pointerdown", function (event) {
    event.preventDefault();
    const p = point(event);
    if (tool === "laser") {
      laser = p;
      canvas.setPointerCapture(event.pointerId);
      draw();
      return;
    }
    if (tool === "draw") {
      drawing = true;
      currentPoints = [p];
      canvas.setPointerCapture(event.pointerId);
      draw();
      return;
    }
    const text = window.prompt("Note at " + timeLabel(video.currentTime) + ":");
    if (text && text.trim()) {
      annotations.push({ id: id(), type: "note", time: video.currentTime || 0, x: p.x, y: p.y, text: text.trim().slice(0, 200) });
      sync();
    }
  });

  canvas.addEventListener("pointermove", function (event) {
    const p = point(event);
    if (tool === "laser") laser = p;
    if (tool === "draw" && drawing) currentPoints.push(p);
    draw();
  });

  function finishPointer(event) {
    if (tool === "draw" && drawing && currentPoints.length > 1) {
      annotations.push({ id: id(), type: "draw", time: video.currentTime || 0, points: currentPoints, color: color, size: lineSize });
      sync();
    }
    if (tool === "laser" && laser) {
      // A click/tap with the laser tool drops a saved marker at this moment
      // in the video, instead of just flashing and disappearing.
      annotations.push({ id: id(), type: "laser", time: video.currentTime || 0, x: laser.x, y: laser.y });
      sync();
    }
    drawing = false;
    currentPoints = [];
    if (tool === "laser") laser = null;
    if (event && canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    draw();
  }

  canvas.addEventListener("pointerup", finishPointer);
  canvas.addEventListener("pointercancel", finishPointer);
  canvas.addEventListener("pointerleave", function () { if (!drawing) { laser = null; draw(); } });
  video.addEventListener("timeupdate", draw);
  video.addEventListener("seeked", draw);
  video.addEventListener("loadedmetadata", resize);
  window.addEventListener("resize", resize);
  if (window.ResizeObserver) new ResizeObserver(resize).observe(wrap);

  tools.querySelectorAll("[data-tool]").forEach(function (button) {
    button.addEventListener("click", () => setTool(button.dataset.tool));
  });
  tools.querySelectorAll("[data-color]").forEach(function (button) {
    button.addEventListener("click", function () {
      color = button.dataset.color;
      tools.querySelectorAll("[data-color]").forEach((candidate) => candidate.classList.toggle("is-active", candidate === button));
    });
  });
  const sizeSelect = tools.querySelector("[data-line-size]");
  sizeSelect.addEventListener("change", () => { lineSize = Number(sizeSelect.value) || 3; });
  tools.querySelector("[data-tme-clear]").addEventListener("click", function () {
    if (!annotations.length || window.confirm("Clear all saved drawings and notes?")) {
      annotations = [];
      sync();
    }
  });
  form.addEventListener("submit", function () { hidden.value = JSON.stringify(annotations); });

  tools.querySelector('[data-color="#ef4444"]').classList.add("is-active");
  setTool("laser");
  sync();
  resize();
})();
