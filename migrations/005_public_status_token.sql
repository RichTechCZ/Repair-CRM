-- Public order status token for customer QR links (servis.expert/status/?id=XXXXXXXX).
-- 8-character opaque tokens; never expose sequential order IDs in public URLs.

ALTER TABLE `orders`
  ADD COLUMN `public_status_token` CHAR(8) NULL DEFAULT NULL AFTER `id`,
  ADD UNIQUE KEY `uq_orders_public_status_token` (`public_status_token`);
