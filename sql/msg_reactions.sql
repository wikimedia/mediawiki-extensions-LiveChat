BEGIN;

CREATE TABLE /*_*/lch_msg_reactions (
  lchmr_msg_id BIGINT UNSIGNED NOT NULL,
  lchmr_user_id INT UNSIGNED NULL,
  lchmr_user_text VARCHAR(255) NOT NULL,
  lchmr_type SMALLINT UNSIGNED NOT NULL,
  lchmr_timestamp varbinary(14) NOT NULL default '',
  PRIMARY KEY (lchmr_msg_id, lchmr_user_text)
)/*$wgDBTableOptions*/;

COMMIT;
