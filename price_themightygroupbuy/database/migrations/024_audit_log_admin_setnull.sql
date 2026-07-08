-- Backlog #33: the admin audit log must outlive the admin it records. It was
-- FK admin_id -> pc_users ON DELETE CASCADE, so deleting an admin erased their
-- entire audit trail. Make admin_id nullable and switch to ON DELETE SET NULL
-- so the rows survive (with admin_id NULL) as an audit record should.
ALTER TABLE pc_admin_audit_log DROP FOREIGN KEY pc_admin_audit_log_ibfk_1;
ALTER TABLE pc_admin_audit_log MODIFY admin_id INT UNSIGNED NULL;
ALTER TABLE pc_admin_audit_log ADD CONSTRAINT pc_admin_audit_log_ibfk_1
  FOREIGN KEY (admin_id) REFERENCES pc_users(id) ON DELETE SET NULL;
