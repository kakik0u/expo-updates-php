CREATE TABLE projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_used_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE releases (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  channel VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  message VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  INDEX (project_id, channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  size BIGINT UNSIGNED NOT NULL,
  content_type VARCHAR(150) NOT NULL,
  file_extension VARCHAR(30) NULL,
  storage_path VARCHAR(255) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE updates (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  release_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  platform ENUM('ios','android') NOT NULL,
  runtime_version VARCHAR(255) NOT NULL,
  channel VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  status ENUM('uploading','active','disabled','failed') NOT NULL DEFAULT 'uploading',
  source_update_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  manifest_json MEDIUMTEXT NULL,
  manifest_signature TEXT NULL,
  launch_asset_id BIGINT UNSIGNED NULL,
  FOREIGN KEY (release_id) REFERENCES releases(id),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (source_update_id) REFERENCES updates(id),
  FOREIGN KEY (launch_asset_id) REFERENCES assets(id),
  INDEX selection (project_id, platform, runtime_version, channel, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE update_assets (
  update_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  asset_id BIGINT UNSIGNED NOT NULL,
  asset_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_launch_asset BOOLEAN NOT NULL DEFAULT FALSE,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (update_id, asset_id, asset_key),
  FOREIGN KEY (update_id) REFERENCES updates(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id),
  INDEX (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE directives (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  platform ENUM('ios','android') NOT NULL,
  runtime_version VARCHAR(255) NOT NULL,
  channel VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type VARCHAR(80) NOT NULL,
  body_json TEXT NOT NULL,
  signature TEXT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  INDEX selection (project_id, platform, runtime_version, channel, active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE patches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  platform ENUM('ios','android') NOT NULL,
  from_update_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  to_update_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY patch_pair (from_update_id, to_update_id),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (from_update_id) REFERENCES updates(id),
  FOREIGN KEY (to_update_id) REFERENCES updates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
