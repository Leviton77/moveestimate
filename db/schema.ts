import { sql } from "drizzle-orm";
import { integer, sqliteTable, text } from "drizzle-orm/sqlite-core";

export const sessions = sqliteTable("sessions", {
  id: text("id").primaryKey(),
  clientName: text("client_name").notNull(),
  email: text("email").notNull(),
  phone: text("phone").notNull(),
  currentAddress: text("current_address").notNull(),
  destinationAddress: text("destination_address").notNull(),
  moveDate: text("move_date").notNull(),
  estimatedSize: text("estimated_size").notNull(),
  specialItems: text("special_items"),
  status: text("status").notNull().default("new"),
  videoKey: text("video_key"),
  videoContentType: text("video_content_type"),
  videoSize: integer("video_size"),
  repNotes: text("rep_notes").notNull().default(""),
  annotations: text("annotations").notNull().default("[]"),
  createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const videoSessions = sqliteTable("video_sessions", {
  id: text("id").primaryKey(),
  estimateSessionId: text("estimate_session_id"),
  repEmail: text("rep_email").notNull(),
  status: text("status").notNull().default("waiting"),
  videoKey: text("video_key"),
  videoContentType: text("video_content_type"),
  videoSize: integer("video_size"),
  createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});
