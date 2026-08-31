-- An order can be for more than one product.
--
-- A partner pays once, uploads one proof, and the tier above makes one
-- decision — so an order for four stoves and two TukTuk kits has to be one
-- order, not two. Approving half of a single payment is not a thing anybody
-- can act on.
--
-- The products move onto lines. `stock_orders` keeps what belongs to the order
-- as a whole: who, from whom, the total, the proof, the decision.

CREATE TABLE IF NOT EXISTS stock_order_items (
  id         int(10) unsigned NOT NULL AUTO_INCREMENT,
  order_id   int(10) unsigned NOT NULL,
  product    enum('stove','tuktuk') NOT NULL,
  quantity   int(10) unsigned NOT NULL,
  -- frozen with the order, so a price change never rewrites what was asked for
  unit_price decimal(10,2) NOT NULL,
  line_total decimal(12,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_order_product (order_id, product),
  CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES stock_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every order that already exists becomes a one-line order
INSERT INTO stock_order_items (order_id, product, quantity, unit_price, line_total)
SELECT id, product, quantity, unit_price, total_amount
  FROM stock_orders
 WHERE product IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM stock_order_items i WHERE i.order_id = stock_orders.id);

-- the columns the lines replaced. Left in place but no longer written or read:
-- dropping them would take the old rows' history with them if anything still
-- refers to it, and they cost nothing where they are.
ALTER TABLE stock_orders
  MODIFY product  enum('stove','tuktuk') DEFAULT NULL,
  MODIFY quantity int(10) unsigned DEFAULT NULL,
  MODIFY unit_price decimal(10,2) DEFAULT NULL;
