(function () {
  "use strict";

  const form = document.querySelector("[data-tme-review-form]");
  const root = form ? form.querySelector("[data-tme-ai-editor]") : null;
  const hidden = form ? form.querySelector("[data-tme-ai-report]") : null;
  const saveState = form ? form.querySelector("[data-tme-ai-save-state]") : null;
  if (!form || !root || !hidden) return;

  let report;
  try {
    report = JSON.parse(hidden.value || "{}");
  } catch (_) {
    root.textContent = "The report could not be opened.";
    return;
  }

  const arrays = function (value) { return Array.isArray(value) ? value : []; };
  const object = function (value) { return value && typeof value === "object" && !Array.isArray(value) ? value : {}; };
  const number = function (value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  };
  const integer = function (value) { return Math.max(0, Math.round(number(value))); };

  function id(prefix) {
    if (window.crypto && window.crypto.randomUUID) return prefix + "_" + window.crypto.randomUUID();
    return prefix + "_" + Date.now().toString(36) + Math.random().toString(36).slice(2);
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function button(text, className, onClick) {
    const control = el("button", className || "button", text);
    control.type = "button";
    control.addEventListener("click", onClick);
    return control;
  }

  function field(labelText, control, className) {
    const label = el("label", "tme-ai-field" + (className ? " " + className : ""));
    label.append(el("span", "", labelText), control);
    return label;
  }

  function textControl(value, onChange, options) {
    const settings = options || {};
    const control = document.createElement(settings.multiline ? "textarea" : "input");
    if (!settings.multiline) control.type = settings.type || "text";
    control.value = value === null || value === undefined ? "" : String(value);
    if (settings.placeholder) control.placeholder = settings.placeholder;
    if (settings.multiline) control.rows = settings.rows || 2;
    if (settings.min !== undefined) control.min = String(settings.min);
    if (settings.max !== undefined) control.max = String(settings.max);
    if (settings.step !== undefined) control.step = String(settings.step);
    control.addEventListener("input", function () {
      const next = settings.type === "number" ? number(control.value) : control.value;
      onChange(next);
      sync(true);
    });
    return control;
  }

  function selectControl(value, choices, onChange) {
    const control = document.createElement("select");
    choices.forEach(function (choice) {
      const option = document.createElement("option");
      option.value = choice[0];
      option.textContent = choice[1];
      option.selected = String(value) === choice[0];
      control.appendChild(option);
    });
    control.addEventListener("change", function () {
      onChange(control.value);
      sync(true);
    });
    return control;
  }

  function checkboxControl(value, onChange, labelText) {
    const label = el("label", "tme-ai-check");
    const control = document.createElement("input");
    control.type = "checkbox";
    control.checked = Boolean(value);
    control.addEventListener("change", function () {
      onChange(control.checked);
      sync(true);
      render();
    });
    label.append(control, document.createTextNode(labelText));
    return label;
  }

  function section(title, description) {
    const block = el("section", "tme-ai-edit-section");
    const heading = el("div", "tme-ai-edit-heading");
    heading.appendChild(el("h3", "", title));
    if (description) heading.appendChild(el("p", "", description));
    block.appendChild(heading);
    return block;
  }

  function ensureReport() {
    report.rooms = arrays(report.rooms);
    report.summary = object(report.summary);
    report.box_estimate = object(report.box_estimate);
    report.box_estimate.total = object(report.box_estimate.total);
    report.disassembly_plan = object(report.disassembly_plan);
    report.disassembly_plan.items = arrays(report.disassembly_plan.items);
    report.disassembly_plan.totals = object(report.disassembly_plan.totals);
    report.mattress_bags = object(report.mattress_bags);
    report.mattress_bags.by_size = arrays(report.mattress_bags.by_size);
    report.mattress_bags.unconfirmed = arrays(report.mattress_bags.unconfirmed);
    report.access = object(report.access);
    report.access.origin = object(report.access.origin);
    report.access.destination = object(report.access.destination);
    report.home_layout = object(report.home_layout);
    report.questions = arrays(report.questions);
    report.review = object(report.review);
    report.review.changes = arrays(report.review.changes);
  }

  function recalculate() {
    ensureReport();
    let moving = 0;
    let notMoving = 0;
    let uncertain = 0;
    let special = 0;
    const boxes = { low: 0, likely: 0, high: 0 };

    report.rooms.forEach(function (room) {
      room.inventory = arrays(room.inventory);
      room.inventory.forEach(function (item) {
        item.box_equivalents = object(item.box_equivalents);
        item.handling_tags = arrays(item.handling_tags);
        const quantity = Math.max(1, integer(item.quantity || 1));
        if (!item.boxable) {
          if (item.move_status === "moving") moving += quantity;
          else if (item.move_status === "not_moving") notMoving += quantity;
          else uncertain += quantity;
        }
        if (item.handling_tags.length) special += 1;
        if (item.boxable && item.move_status === "moving") {
          boxes.low += integer(item.box_equivalents.low);
          boxes.likely += integer(item.box_equivalents.likely);
          boxes.high += integer(item.box_equivalents.high);
        }
      });
    });

    const disassembly = { likely: 0, may_be_needed: 0, required: 0 };
    report.disassembly_plan.items.forEach(function (item) {
      if (Object.prototype.hasOwnProperty.call(disassembly, item.likelihood)) disassembly[item.likelihood] += 1;
    });
    report.disassembly_plan.totals = disassembly;

    let bagTotal = 0;
    const bagSizes = {};
    report.mattress_bags.by_size.forEach(function (entry) {
      entry.mattress_bags = integer(entry.mattress_bags);
      entry.foundation_or_box_spring_bags = integer(entry.foundation_or_box_spring_bags);
      entry.total_bags = entry.mattress_bags + entry.foundation_or_box_spring_bags;
      bagTotal += entry.total_bags;
      const size = entry.size || "unknown";
      bagSizes[size] = (bagSizes[size] || 0) + entry.total_bags;
    });
    report.mattress_bags.total_bags = bagTotal;

    report.summary.rooms_observed = report.rooms.length;
    report.summary.moving_furniture_pieces = moving;
    report.summary.not_moving_furniture_pieces = notMoving;
    report.summary.uncertain_furniture_pieces = uncertain;
    report.summary.box_equivalents = boxes;
    report.summary.special_handling_items = special;
    report.summary.disassembly = disassembly;
    report.summary.mattress_bags_total = bagTotal;
    report.summary.mattress_bag_sizes = bagSizes;
    report.summary.unresolved_questions = report.questions.filter(function (question) { return (question.status || "open") === "open"; }).length;
    report.box_estimate.total = boxes;
  }

  function sync(dirty) {
    recalculate();
    hidden.value = JSON.stringify(report);
    renderSummary();
    if (dirty && saveState) {
      saveState.textContent = "Unsaved report changes.";
      saveState.classList.add("is-dirty");
    }
  }

  function summaryCard(label, value) {
    const card = el("div", "tme-ai-summary-card");
    card.append(el("span", "", label), el("strong", "", String(value)));
    return card;
  }

  function renderSummary() {
    const target = root.querySelector("[data-tme-ai-summary]");
    if (!target) return;
    const summary = object(report.summary);
    const boxes = object(summary.box_equivalents);
    const disassembly = object(summary.disassembly);
    target.textContent = "";
    target.append(
      summaryCard("Rooms", summary.rooms_observed || 0),
      summaryCard("Moving furniture", summary.moving_furniture_pieces || 0),
      summaryCard("Excluded", summary.not_moving_furniture_pieces || 0),
      summaryCard("Uncertain", summary.uncertain_furniture_pieces || 0),
      summaryCard("Boxes", (boxes.low || 0) + " / " + (boxes.likely || 0) + " / " + (boxes.high || 0)),
      summaryCard("Likely disassembly", disassembly.likely || 0),
      summaryCard("May need disassembly", disassembly.may_be_needed || 0),
      summaryCard("Mattress bags", summary.mattress_bags_total || 0),
      summaryCard("Open questions", summary.unresolved_questions || 0)
    );
  }

  function evidenceLabel(evidence) {
    const entries = arrays(evidence);
    if (!entries.length) return "No timestamp";
    return entries.map(function (item) {
      if (item.type !== "video_timestamp") return item.note || "Photo evidence";
      const start = Math.max(0, integer(item.start_seconds));
      const end = Math.max(start, integer(item.end_seconds));
      const time = function (seconds) { return Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0"); };
      return time(start) + "–" + time(end);
    }).join(", ");
  }

  function itemCard(room, item, itemIndex) {
    item.box_equivalents = object(item.box_equivalents);
    item.handling_tags = arrays(item.handling_tags);
    const card = el("article", "tme-ai-item-card");
    const header = el("div", "tme-ai-item-heading");
    header.append(el("strong", "", "Item " + (itemIndex + 1)), button("Remove", "button-link-delete", function () {
      room.inventory.splice(itemIndex, 1);
      sync(true);
      render();
    }));
    card.appendChild(header);

    const basics = el("div", "tme-ai-fields tme-ai-fields--item");
    basics.append(
      field("Item", textControl(item.name || "", function (value) { item.name = value; })),
      field("Category", textControl(item.category || "", function (value) { item.category = value; })),
      field("Quantity", textControl(item.quantity || 1, function (value) { item.quantity = integer(value); }, { type: "number", min: 1, step: 1 })),
      field("Move status", selectControl(item.move_status || "uncertain", [["moving", "Moving"], ["not_moving", "Not moving"], ["uncertain", "Uncertain"]], function (value) { item.move_status = value; })),
      field("Confidence", textControl(item.confidence || 0, function (value) { item.confidence = Math.max(0, Math.min(1, number(value))); }, { type: "number", min: 0, max: 1, step: 0.01 }))
    );
    basics.appendChild(checkboxControl(Boolean(item.boxable), function (value) { item.boxable = value; }, "Boxable contents"));
    card.appendChild(basics);

    const boxes = el("div", "tme-ai-fields tme-ai-fields--boxes");
    boxes.append(
      field("Boxes low", textControl(item.box_equivalents.low || 0, function (value) { item.box_equivalents.low = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Boxes likely", textControl(item.box_equivalents.likely || 0, function (value) { item.box_equivalents.likely = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Boxes high", textControl(item.box_equivalents.high || 0, function (value) { item.box_equivalents.high = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Handling tags", textControl(item.handling_tags.join(", "), function (value) {
        item.handling_tags = value.split(",").map(function (tag) { return tag.trim(); }).filter(Boolean);
      }, { placeholder: "heavy, bulky, fragile" }))
    );
    card.appendChild(boxes);
    card.appendChild(field("Representative notes", textControl(item.notes || "", function (value) { item.notes = value; }, { multiline: true, rows: 2 })));
    card.appendChild(el("p", "tme-ai-evidence", "Evidence: " + evidenceLabel(item.evidence)));
    return card;
  }

  function renderInventory() {
    const block = section("Inventory by room", "Edit the room, item, quantity, moving status, box range and handling information.");
    report.rooms.forEach(function (room, roomIndex) {
      room.inventory = arrays(room.inventory);
      const card = el("article", "tme-ai-room");
      const heading = el("div", "tme-ai-room-heading");
      const fields = el("div", "tme-ai-fields tme-ai-fields--room");
      fields.append(
        field("Room", textControl(room.name || "", function (value) { room.name = value; })),
        field("Floor", textControl(room.floor || "unknown", function (value) { room.floor = value; }))
      );
      heading.append(fields, button("Remove room", "button-link-delete", function () {
        if (window.confirm("Remove this room and all of its items from the report?")) {
          report.rooms.splice(roomIndex, 1);
          sync(true);
          render();
        }
      }));
      card.appendChild(heading);
      const items = el("div", "tme-ai-item-list");
      room.inventory.forEach(function (item, itemIndex) { items.appendChild(itemCard(room, item, itemIndex)); });
      card.appendChild(items);
      card.appendChild(button("Add item", "button", function () {
        room.inventory.push({
          id: id("item"),
          name: "New item",
          category: "furniture",
          quantity: 1,
          move_status: "uncertain",
          boxable: false,
          box_equivalents: { low: 0, likely: 0, high: 0 },
          handling_tags: [],
          confidence: 1,
          evidence: [],
          notes: ""
        });
        sync(true);
        render();
      }));
      block.appendChild(card);
    });
    block.appendChild(button("Add room", "button button-secondary", function () {
      report.rooms.push({ id: id("room"), name: "New room", floor: "unknown", inventory: [] });
      sync(true);
      render();
    }));
    root.appendChild(block);
  }

  function renderDisassembly() {
    const block = section("Disassembly and reassembly", "Keep likely work separate from work that may only be needed because of the carrying route.");
    report.disassembly_plan.items.forEach(function (item, index) {
      const row = el("article", "tme-ai-repeat-card");
      const heading = el("div", "tme-ai-item-heading");
      heading.append(el("strong", "", "Recommendation " + (index + 1)), button("Remove", "button-link-delete", function () {
        report.disassembly_plan.items.splice(index, 1);
        sync(true);
        render();
      }));
      const fields = el("div", "tme-ai-fields tme-ai-fields--repeat");
      fields.append(
        field("Item", textControl(item.item || "", function (value) { item.item = value; })),
        field("Room", textControl(item.room_id || "", function (value) { item.room_id = value; })),
        field("Likelihood", selectControl(item.likelihood || "may_be_needed", [["not_expected", "Not expected"], ["may_be_needed", "May be needed"], ["likely", "Likely"], ["required", "Required"]], function (value) { item.likelihood = value; })),
        field("Destination reassembly", selectControl(String(item.reassembly_at_destination || "unknown"), [["yes", "Yes"], ["likely", "Likely"], ["no", "No"], ["unknown", "Unknown"]], function (value) { item.reassembly_at_destination = value; }))
      );
      row.append(heading, fields, field("Expected work", textControl(item.expected_work || "", function (value) { item.expected_work = value; }, { multiline: true, rows: 2 })));
      block.appendChild(row);
    });
    block.appendChild(button("Add disassembly item", "button", function () {
      report.disassembly_plan.items.push({
        item_id: id("item"),
        room_id: "",
        item: "New item",
        quantity: 1,
        likelihood: "may_be_needed",
        expected_work: "",
        reassembly_at_destination: "unknown",
        basis: [],
        confidence: 1,
        evidence_seconds: []
      });
      sync(true);
      render();
    }));
    root.appendChild(block);
  }

  function renderMattressBags() {
    const block = section("Mattress bags", "One bag is normally needed for each mattress and each foundation or box spring.");
    const sizes = [["crib_toddler", "Crib / toddler"], ["single_twin", "Single / twin"], ["twin_xl", "Twin XL"], ["double_full", "Double / full"], ["queen", "Queen"], ["king", "King"], ["california_king", "California king"], ["unknown", "Unknown"]];
    report.mattress_bags.by_size.forEach(function (entry, index) {
      const row = el("article", "tme-ai-repeat-card");
      const fields = el("div", "tme-ai-fields tme-ai-fields--bags");
      fields.append(
        field("Size", selectControl(entry.size || "unknown", sizes, function (value) { entry.size = value; })),
        field("Mattress bags", textControl(entry.mattress_bags || 0, function (value) { entry.mattress_bags = integer(value); }, { type: "number", min: 0, step: 1 })),
        field("Foundation bags", textControl(entry.foundation_or_box_spring_bags || 0, function (value) { entry.foundation_or_box_spring_bags = integer(value); }, { type: "number", min: 0, step: 1 }))
      );
      row.append(fields, el("strong", "tme-ai-bag-total", "Total: " + integer(entry.total_bags)), button("Remove", "button-link-delete", function () {
        report.mattress_bags.by_size.splice(index, 1);
        sync(true);
        render();
      }));
      block.appendChild(row);
    });
    block.appendChild(button("Add bag size", "button", function () {
      report.mattress_bags.by_size.push({ size: "unknown", mattress_bags: 1, foundation_or_box_spring_bags: 0, total_bags: 1, room_ids: [], item_ids: [], confidence: 1, evidence: [] });
      sync(true);
      render();
    }));
    root.appendChild(block);
  }

  function ensureAccess(location) {
    location.outdoor_carry_distance_meters = object(location.outdoor_carry_distance_meters);
    location.entrance = object(location.entrance);
    location.corridors = object(location.corridors);
    location.elevator = object(location.elevator);
    location.stairs = object(location.stairs);
    location.surface = arrays(location.surface);
  }

  function accessCard(title, location) {
    ensureAccess(location);
    const card = el("article", "tme-ai-access-card");
    card.appendChild(el("h4", "", title));
    card.appendChild(checkboxControl(Boolean(location.observed), function (value) { location.observed = value; }, "Location observed"));
    const fields = el("div", "tme-ai-fields tme-ai-fields--access");
    fields.append(
      field("Truck position", textControl(location.truck_position || "unknown", function (value) { location.truck_position = value; })),
      field("Parking restrictions", textControl(location.parking_restrictions || "unknown", function (value) { location.parking_restrictions = value; })),
      field("Carry low (metres)", textControl(location.outdoor_carry_distance_meters.low || 0, function (value) { location.outdoor_carry_distance_meters.low = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Carry likely (metres)", textControl(location.outdoor_carry_distance_meters.likely || 0, function (value) { location.outdoor_carry_distance_meters.likely = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Carry high (metres)", textControl(location.outdoor_carry_distance_meters.high || 0, function (value) { location.outdoor_carry_distance_meters.high = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Exterior steps", textControl(location.stairs.exterior_steps || 0, function (value) { location.stairs.exterior_steps = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Interior flights", textControl(location.stairs.interior_flights || 0, function (value) { location.stairs.interior_flights = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Basement flights", textControl(location.stairs.basement_flights || 0, function (value) { location.stairs.basement_flights = integer(value); }, { type: "number", min: 0, step: 1 })),
      field("Carry complexity", selectControl(location.carry_complexity || "unknown", [["low", "Low"], ["medium", "Medium"], ["high", "High"], ["unknown", "Unknown"]], function (value) { location.carry_complexity = value; })),
      field("Surface", textControl(location.surface.join(", "), function (value) { location.surface = value.split(",").map(function (part) { return part.trim(); }).filter(Boolean); })),
      field("Corridor notes", textControl(location.corridors.notes || "", function (value) { location.corridors.notes = value; }, { multiline: true, rows: 2 }))
    );
    card.appendChild(fields);
    return card;
  }

  function renderAccess() {
    const block = section("Access and home layout", "Keep origin and destination separate. The more restrictive route controls the carrying plan.");
    const locations = el("div", "tme-ai-access-grid");
    locations.append(accessCard("Origin", report.access.origin), accessCard("Destination", report.access.destination));
    block.appendChild(locations);

    report.home_layout.estimated_total_area_sqft = object(report.home_layout.estimated_total_area_sqft);
    report.home_layout.levels_with_moving_items = arrays(report.home_layout.levels_with_moving_items);
    const home = el("article", "tme-ai-access-card");
    home.appendChild(el("h4", "", "Home distribution"));
    const fields = el("div", "tme-ai-fields tme-ai-fields--access");
    fields.append(
      field("Area low (sq. ft.)", textControl(report.home_layout.estimated_total_area_sqft.low || 0, function (value) { report.home_layout.estimated_total_area_sqft.low = integer(value); }, { type: "number", min: 0, step: 50 })),
      field("Area likely (sq. ft.)", textControl(report.home_layout.estimated_total_area_sqft.likely || 0, function (value) { report.home_layout.estimated_total_area_sqft.likely = integer(value); }, { type: "number", min: 0, step: 50 })),
      field("Area high (sq. ft.)", textControl(report.home_layout.estimated_total_area_sqft.high || 0, function (value) { report.home_layout.estimated_total_area_sqft.high = integer(value); }, { type: "number", min: 0, step: 50 })),
      field("Floors with items", textControl(report.home_layout.levels_with_moving_items.join(", "), function (value) { report.home_layout.levels_with_moving_items = value.split(",").map(function (part) { return part.trim(); }).filter(Boolean); })),
      field("Distribution", selectControl(report.home_layout.distribution_type || "unknown", [["packed_storage", "Packed storage"], ["distributed_household", "Distributed household"], ["mixed", "Mixed"], ["unknown", "Unknown"]], function (value) { report.home_layout.distribution_type = value; })),
      field("Item density", selectControl(report.home_layout.item_density || "unknown", [["low", "Low"], ["medium", "Medium"], ["high", "High"], ["unknown", "Unknown"]], function (value) { report.home_layout.item_density = value; })),
      field("Carrying-speed factor", selectControl(report.home_layout.carrying_speed_factor || "standard", [["faster", "Faster"], ["standard", "Standard"], ["slower", "Slower"]], function (value) { report.home_layout.carrying_speed_factor = value; }))
    );
    home.appendChild(fields);
    block.appendChild(home);
    root.appendChild(block);
  }

  function renderQuestions() {
    const block = section("Questions and uncertainty", "Open questions remain visible until a representative answers or dismisses them.");
    report.questions.forEach(function (question, index) {
      const row = el("article", "tme-ai-repeat-card");
      const fields = el("div", "tme-ai-fields tme-ai-fields--questions");
      fields.append(
        field("Question", textControl(question.question || "", function (value) { question.question = value; }, { multiline: true, rows: 2 })),
        field("Priority", selectControl(question.priority || "medium", [["high", "High"], ["medium", "Medium"], ["low", "Low"]], function (value) { question.priority = value; })),
        field("Status", selectControl(question.status || "open", [["open", "Open"], ["answered", "Answered"], ["dismissed", "Dismissed"]], function (value) { question.status = value; }))
      );
      row.append(fields, button("Remove", "button-link-delete", function () {
        report.questions.splice(index, 1);
        sync(true);
        render();
      }));
      block.appendChild(row);
    });
    block.appendChild(button("Add question", "button", function () {
      report.questions.push({ id: id("question"), priority: "medium", status: "open", question: "", related_item_ids: [], evidence_seconds: [] });
      sync(true);
      render();
    }));
    root.appendChild(block);
  }

  function renderReview() {
    const block = section("Review record", "Approval records the representative and preserves the original synthetic draft.");
    block.appendChild(field("Approval notes", textControl(report.review.approval_notes || "", function (value) { report.review.approval_notes = value; }, { multiline: true, rows: 3 })));
    block.appendChild(el("p", "description", report.review.changes.length + " recorded field change" + (report.review.changes.length === 1 ? "" : "s") + "."));
    root.appendChild(block);
  }

  function render() {
    ensureReport();
    root.textContent = "";
    const summary = el("div", "tme-ai-summary");
    summary.setAttribute("data-tme-ai-summary", "");
    root.appendChild(summary);
    renderSummary();
    renderInventory();
    renderDisassembly();
    renderMattressBags();
    renderAccess();
    renderQuestions();
    renderReview();
    sync(false);
  }

  form.addEventListener("submit", function () { sync(false); });
  const approve = form.querySelector('button[name="ai_action"][value="approve"]');
  if (approve) {
    approve.addEventListener("click", function (event) {
      if (!window.confirm("Approve this report as the representative-reviewed version?")) event.preventDefault();
    });
  }

  render();
})();
