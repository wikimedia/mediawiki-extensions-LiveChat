BEGIN;

CREATE TABLE /*_*/lch_messages (
  lchm_id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  lchm_parent_id BIGINT UNSIGNED NULL,
  lchm_user_id INT UNSIGNED NULL,
  lchm_user_text VARCHAR(255) NOT NULL,
  lchm_room_type SMALLINT UNSIGNED NOT NULL,
  lchm_room_id INT UNSIGNED NOT NULL,
  lchm_message VARBINARY(1000),
  lchm_has_children BOOLEAN NOT NULL default false,
  lchm_timestamp varbinary(14) NOT NULL default ''
)/*$wgDBTableOptions*/;

CREATE INDEX /*i*/lch_room ON /*_*/lch_messages(lchm_room_type,lchm_room_id,lchm_timestamp);
# CREATE INDEX /*i*/lch_parent ON /*_*/lch_messages(lch_room_type,lch_room_id,lch_parent_id);

COMMIT;
