-- Append the waiting stage, preserving the numeric values of every existing status.
ALTER TABLE orders MODIFY COLUMN status ENUM(
    'Accepted','Diagnostics','Approval','In Repair','Ready','Issued',
    'Issued Without Repair','Repair Cancelled','Waiting for Parts'
) NOT NULL DEFAULT 'Accepted';
