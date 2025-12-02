-- Migration: Add imports table to track import history
-- This allows users to undo individual imports

CREATE TABLE IF NOT EXISTS imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT,
    bookmark_ids TEXT NOT NULL,  -- JSON array of bookmark IDs
    created_count INTEGER DEFAULT 0,
    updated_count INTEGER DEFAULT 0,
    additional_tags TEXT,  -- Comma-separated tags that were added
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_imports_created ON imports(created_at);

