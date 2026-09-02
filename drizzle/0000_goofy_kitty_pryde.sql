CREATE TABLE `sessions` (
	`id` text PRIMARY KEY NOT NULL,
	`client_name` text NOT NULL,
	`email` text NOT NULL,
	`phone` text NOT NULL,
	`current_address` text NOT NULL,
	`destination_address` text NOT NULL,
	`move_date` text NOT NULL,
	`estimated_size` text NOT NULL,
	`special_items` text,
	`status` text DEFAULT 'new' NOT NULL,
	`video_key` text,
	`video_content_type` text,
	`video_size` integer,
	`rep_notes` text DEFAULT '' NOT NULL,
	`annotations` text DEFAULT '[]' NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
