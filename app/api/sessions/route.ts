import { createSession } from "../../../db/sessions";

const HOME_SIZES = new Set(["Studio", "1BR", "2BR", "3BR", "House"]);

function text(value: unknown, max: number) {
  return typeof value === "string" ? value.trim().slice(0, max) : "";
}

export async function POST(request: Request) {
  try {
    const payload = (await request.json()) as Record<string, unknown>;
    if (text(payload.website, 200)) {
      return Response.json({ ok: true }, { status: 202 });
    }

    const input = {
      client_name: text(payload.clientName, 120),
      email: text(payload.email, 160).toLowerCase(),
      phone: text(payload.phone, 40),
      current_address: text(payload.currentAddress, 240),
      destination_address: text(payload.destinationAddress, 240),
      move_date: text(payload.moveDate, 10),
      estimated_size: text(payload.estimatedSize, 20),
      special_items: text(payload.specialItems, 1200) || null,
    };

    const required = [
      input.client_name,
      input.email,
      input.phone,
      input.current_address,
      input.destination_address,
      input.move_date,
      input.estimated_size,
    ];
    if (required.some((value) => !value)) {
      return Response.json({ error: "Please complete every required field." }, { status: 400 });
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.email)) {
      return Response.json({ error: "Please enter a valid email address." }, { status: 400 });
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(input.move_date)) {
      return Response.json({ error: "Please choose a valid move date." }, { status: 400 });
    }
    if (!HOME_SIZES.has(input.estimated_size)) {
      return Response.json({ error: "Please choose a home size." }, { status: 400 });
    }

    const id = await createSession(input);
    return Response.json({ id }, { status: 201 });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unable to create your estimate.";
    return Response.json({ error: message }, { status: 500 });
  }
}
