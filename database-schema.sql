-- Bampo Outfit POS relational foundation for PostgreSQL
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE TYPE user_role AS ENUM ('admin','manager','cashier');
CREATE TYPE payment_method AS ENUM ('cash','mpesa','airtel_money','mixx_by_yas','tigo_pesa','bank','card');
CREATE TYPE stock_movement_type AS ENUM ('stock_in','sale','return','adjustment','damaged_lost');

CREATE TABLE roles (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), name user_role UNIQUE NOT NULL);
CREATE TABLE users (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), role_id uuid NOT NULL REFERENCES roles(id), full_name varchar(120) NOT NULL, email varchar(180) UNIQUE NOT NULL, password_hash text NOT NULL, is_active boolean DEFAULT true, created_at timestamptz DEFAULT now(), deleted_at timestamptz);
CREATE TABLE categories (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), name varchar(100) UNIQUE NOT NULL, created_at timestamptz DEFAULT now(), deleted_at timestamptz);
CREATE TABLE brands (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), name varchar(100) UNIQUE NOT NULL);
CREATE TABLE products (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), category_id uuid REFERENCES categories(id), brand_id uuid REFERENCES brands(id), name varchar(180) NOT NULL, sku varchar(80) UNIQUE NOT NULL, description text, image_url text, purchase_price numeric(12,2) NOT NULL CHECK (purchase_price >= 0), selling_price numeric(12,2) NOT NULL CHECK (selling_price >= 0), discount numeric(12,2) DEFAULT 0 CHECK (discount >= 0), minimum_stock integer DEFAULT 5 CHECK (minimum_stock >= 0), created_at timestamptz DEFAULT now(), deleted_at timestamptz);
CREATE TABLE product_variants (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), product_id uuid NOT NULL REFERENCES products(id), size varchar(30), color varchar(50), sku varchar(80) UNIQUE NOT NULL, quantity integer DEFAULT 0 CHECK (quantity >= 0), UNIQUE(product_id,size,color));
CREATE TABLE customers (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), full_name varchar(120) NOT NULL, phone varchar(40), email varchar(180), address text, balance numeric(12,2) DEFAULT 0, created_at timestamptz DEFAULT now(), deleted_at timestamptz);
CREATE TABLE suppliers (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), name varchar(140) NOT NULL, phone varchar(40), email varchar(180), address text, amount_owed numeric(12,2) DEFAULT 0, deleted_at timestamptz);
CREATE TABLE sales (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), receipt_number varchar(40) UNIQUE NOT NULL, customer_id uuid REFERENCES customers(id), cashier_id uuid NOT NULL REFERENCES users(id), subtotal numeric(12,2) NOT NULL, discount numeric(12,2) DEFAULT 0, tax numeric(12,2) DEFAULT 0, total numeric(12,2) NOT NULL, status varchar(30) DEFAULT 'paid', sold_at timestamptz DEFAULT now());
CREATE TABLE sale_items (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), sale_id uuid NOT NULL REFERENCES sales(id), variant_id uuid NOT NULL REFERENCES product_variants(id), quantity integer NOT NULL CHECK (quantity > 0), unit_price numeric(12,2) NOT NULL, purchase_price numeric(12,2) NOT NULL, total numeric(12,2) NOT NULL);
CREATE TABLE payments (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), sale_id uuid REFERENCES sales(id), method payment_method NOT NULL, amount numeric(12,2) NOT NULL, paid_at timestamptz DEFAULT now());
CREATE TABLE stock_movements (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), variant_id uuid NOT NULL REFERENCES product_variants(id), type stock_movement_type NOT NULL, quantity integer NOT NULL, reason text, reference_id uuid, created_by uuid NOT NULL REFERENCES users(id), created_at timestamptz DEFAULT now());
CREATE TABLE purchases (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), supplier_id uuid REFERENCES suppliers(id), invoice_number varchar(80) UNIQUE NOT NULL, subtotal numeric(12,2) NOT NULL DEFAULT 0, vat_rate numeric(5,2) NOT NULL DEFAULT 0 CHECK (vat_rate IN (0, 18)), vat_amount numeric(12,2) NOT NULL DEFAULT 0, total_cost numeric(12,2) NOT NULL, payment_status varchar(30) DEFAULT 'pending', purchased_at timestamptz DEFAULT now());
CREATE TABLE purchase_items (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), purchase_id uuid NOT NULL REFERENCES purchases(id), variant_id uuid NOT NULL REFERENCES product_variants(id), quantity integer NOT NULL CHECK (quantity > 0), purchase_price numeric(12,2) NOT NULL, subtotal numeric(12,2) NOT NULL DEFAULT 0, vat_rate numeric(5,2) NOT NULL DEFAULT 0 CHECK (vat_rate IN (0, 18)), vat_amount numeric(12,2) NOT NULL DEFAULT 0, total_cost numeric(12,2) NOT NULL);
CREATE TABLE expenses (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), name varchar(140) NOT NULL, category varchar(60) NOT NULL, amount numeric(12,2) NOT NULL, description text, paid_by uuid REFERENCES users(id), attachment_url text, spent_at date DEFAULT current_date);
CREATE TABLE returns (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), sale_id uuid NOT NULL REFERENCES sales(id), processed_by uuid NOT NULL REFERENCES users(id), reason text NOT NULL, refund_amount numeric(12,2) NOT NULL, refund_method payment_method NOT NULL, returned_at timestamptz DEFAULT now());
CREATE TABLE return_items (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), return_id uuid NOT NULL REFERENCES returns(id), sale_item_id uuid NOT NULL REFERENCES sale_items(id), quantity integer NOT NULL CHECK (quantity > 0));
CREATE TABLE settings (key varchar(80) PRIMARY KEY, value jsonb NOT NULL, updated_at timestamptz DEFAULT now());
CREATE TABLE audit_logs (id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), user_id uuid REFERENCES users(id), action varchar(100) NOT NULL, entity varchar(80) NOT NULL, entity_id uuid, metadata jsonb, created_at timestamptz DEFAULT now());
CREATE INDEX idx_products_category ON products(category_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_products_sku ON products(sku) WHERE deleted_at IS NULL;
CREATE INDEX idx_variants_product ON product_variants(product_id);
CREATE INDEX idx_sales_sold_at ON sales(sold_at);
CREATE INDEX idx_sales_cashier ON sales(cashier_id);
CREATE INDEX idx_stock_movements_variant_date ON stock_movements(variant_id, created_at);
CREATE INDEX idx_customers_phone ON customers(phone);

-- Required application roles. Create the first user with a separately generated
-- password hash; never store a plain-text password in this database.
INSERT INTO roles (name) VALUES ('admin'), ('manager'), ('cashier') ON CONFLICT (name) DO NOTHING;
