ALTER TABLE /*_*/lch_messages ADD COLUMN lchm_has_children BOOLEAN NOT NULL default false AFTER lchm_message;
