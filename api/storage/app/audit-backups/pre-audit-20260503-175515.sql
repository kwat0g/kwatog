--
-- PostgreSQL database dump
--

\restrict CkXjfroXVeXfOIu69qCIrXx6XcEhWYliHHhP5tYH8fW7U80zTv2gHERApsn7OgC

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.13

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: accounts; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.accounts (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(150) NOT NULL,
    type character varying(20) NOT NULL,
    normal_balance character varying(10) NOT NULL,
    parent_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.accounts OWNER TO ogami;

--
-- Name: accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.accounts_id_seq OWNER TO ogami;

--
-- Name: accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.accounts_id_seq OWNED BY public.accounts.id;


--
-- Name: approval_records; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.approval_records (
    id bigint NOT NULL,
    approvable_type character varying(100) NOT NULL,
    approvable_id bigint NOT NULL,
    step_order smallint NOT NULL,
    role_slug character varying(50) NOT NULL,
    approver_id bigint,
    action character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    remarks text,
    acted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.approval_records OWNER TO ogami;

--
-- Name: approval_records_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.approval_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.approval_records_id_seq OWNER TO ogami;

--
-- Name: approval_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.approval_records_id_seq OWNED BY public.approval_records.id;


--
-- Name: approved_suppliers; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.approved_suppliers (
    id bigint NOT NULL,
    item_id bigint NOT NULL,
    vendor_id bigint NOT NULL,
    is_preferred boolean DEFAULT false NOT NULL,
    lead_time_days smallint DEFAULT '0'::smallint NOT NULL,
    last_price numeric(15,2),
    last_price_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.approved_suppliers OWNER TO ogami;

--
-- Name: approved_suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.approved_suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.approved_suppliers_id_seq OWNER TO ogami;

--
-- Name: approved_suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.approved_suppliers_id_seq OWNED BY public.approved_suppliers.id;


--
-- Name: asset_depreciations; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.asset_depreciations (
    id bigint NOT NULL,
    asset_id bigint NOT NULL,
    period_year smallint NOT NULL,
    period_month smallint NOT NULL,
    depreciation_amount numeric(15,2) NOT NULL,
    accumulated_after numeric(15,2) NOT NULL,
    journal_entry_id bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.asset_depreciations OWNER TO ogami;

--
-- Name: asset_depreciations_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.asset_depreciations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.asset_depreciations_id_seq OWNER TO ogami;

--
-- Name: asset_depreciations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.asset_depreciations_id_seq OWNED BY public.asset_depreciations.id;


--
-- Name: assets; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.assets (
    id bigint NOT NULL,
    asset_code character varying(32) NOT NULL,
    name character varying(200) NOT NULL,
    description text,
    category character varying(30) NOT NULL,
    department_id bigint,
    acquisition_date date NOT NULL,
    acquisition_cost numeric(15,2) NOT NULL,
    useful_life_years integer NOT NULL,
    salvage_value numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    accumulated_depreciation numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    disposed_date date,
    disposal_amount numeric(15,2),
    location character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.assets OWNER TO ogami;

--
-- Name: assets_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.assets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.assets_id_seq OWNER TO ogami;

--
-- Name: assets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.assets_id_seq OWNED BY public.assets.id;


--
-- Name: attendances; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.attendances (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    date date NOT NULL,
    shift_id bigint,
    time_in timestamp(0) without time zone,
    time_out timestamp(0) without time zone,
    regular_hours numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    overtime_hours numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    night_diff_hours numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tardiness_minutes integer DEFAULT 0 NOT NULL,
    undertime_minutes integer DEFAULT 0 NOT NULL,
    holiday_type character varying(30),
    is_rest_day boolean DEFAULT false NOT NULL,
    day_type_rate numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    status character varying(20) DEFAULT 'present'::character varying NOT NULL,
    is_manual_entry boolean DEFAULT false NOT NULL,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.attendances OWNER TO ogami;

--
-- Name: attendances_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.attendances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.attendances_id_seq OWNER TO ogami;

--
-- Name: attendances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.attendances_id_seq OWNED BY public.attendances.id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.audit_logs (
    id bigint NOT NULL,
    user_id bigint,
    action character varying(20) NOT NULL,
    model_type character varying(100) NOT NULL,
    model_id bigint,
    old_values json,
    new_values json,
    ip_address character varying(45),
    user_agent text,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.audit_logs OWNER TO ogami;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_logs_id_seq OWNER TO ogami;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: bank_file_records; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.bank_file_records (
    id bigint NOT NULL,
    payroll_period_id bigint NOT NULL,
    file_path character varying(255) NOT NULL,
    record_count integer NOT NULL,
    total_amount numeric(15,2) NOT NULL,
    generated_by bigint NOT NULL,
    generated_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.bank_file_records OWNER TO ogami;

--
-- Name: bank_file_records_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.bank_file_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bank_file_records_id_seq OWNER TO ogami;

--
-- Name: bank_file_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.bank_file_records_id_seq OWNED BY public.bank_file_records.id;


--
-- Name: bill_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.bill_items (
    id bigint NOT NULL,
    bill_id bigint NOT NULL,
    expense_account_id bigint NOT NULL,
    description character varying(200) NOT NULL,
    quantity numeric(12,2) NOT NULL,
    unit character varying(20),
    unit_price numeric(15,2) NOT NULL,
    total numeric(15,2) NOT NULL
);


ALTER TABLE public.bill_items OWNER TO ogami;

--
-- Name: bill_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.bill_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bill_items_id_seq OWNER TO ogami;

--
-- Name: bill_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.bill_items_id_seq OWNED BY public.bill_items.id;


--
-- Name: bill_of_materials; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.bill_of_materials (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.bill_of_materials OWNER TO ogami;

--
-- Name: bill_of_materials_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.bill_of_materials_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bill_of_materials_id_seq OWNER TO ogami;

--
-- Name: bill_of_materials_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.bill_of_materials_id_seq OWNED BY public.bill_of_materials.id;


--
-- Name: bill_payments; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.bill_payments (
    id bigint NOT NULL,
    bill_id bigint NOT NULL,
    cash_account_id bigint NOT NULL,
    payment_date date NOT NULL,
    amount numeric(15,2) NOT NULL,
    payment_method character varying(30) NOT NULL,
    reference_number character varying(50),
    journal_entry_id bigint,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.bill_payments OWNER TO ogami;

--
-- Name: bill_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.bill_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bill_payments_id_seq OWNER TO ogami;

--
-- Name: bill_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.bill_payments_id_seq OWNED BY public.bill_payments.id;


--
-- Name: bills; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.bills (
    id bigint NOT NULL,
    bill_number character varying(50) NOT NULL,
    vendor_id bigint NOT NULL,
    purchase_order_id bigint,
    date date NOT NULL,
    due_date date NOT NULL,
    is_vatable boolean DEFAULT true NOT NULL,
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    vat_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    amount_paid numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    balance numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(20) DEFAULT 'unpaid'::character varying NOT NULL,
    journal_entry_id bigint,
    created_by bigint,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    has_variances boolean DEFAULT false NOT NULL,
    three_way_match_snapshot json,
    three_way_overridden boolean DEFAULT false NOT NULL,
    three_way_overridden_by bigint,
    three_way_overridden_at timestamp(0) without time zone,
    three_way_override_reason text
);


ALTER TABLE public.bills OWNER TO ogami;

--
-- Name: bills_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.bills_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bills_id_seq OWNER TO ogami;

--
-- Name: bills_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.bills_id_seq OWNED BY public.bills.id;


--
-- Name: bom_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.bom_items (
    id bigint NOT NULL,
    bom_id bigint NOT NULL,
    item_id bigint NOT NULL,
    quantity_per_unit numeric(10,4) NOT NULL,
    unit character varying(20) NOT NULL,
    waste_factor numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL
);


ALTER TABLE public.bom_items OWNER TO ogami;

--
-- Name: bom_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.bom_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bom_items_id_seq OWNER TO ogami;

--
-- Name: bom_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.bom_items_id_seq OWNED BY public.bom_items.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO ogami;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO ogami;

--
-- Name: clearances; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.clearances (
    id bigint NOT NULL,
    clearance_no character varying(32) NOT NULL,
    employee_id bigint NOT NULL,
    separation_date date NOT NULL,
    separation_reason character varying(30) NOT NULL,
    clearance_items json NOT NULL,
    final_pay_computed boolean DEFAULT false NOT NULL,
    final_pay_amount numeric(15,2),
    final_pay_breakdown json,
    journal_entry_id bigint,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    initiated_by bigint NOT NULL,
    finalized_at timestamp(0) without time zone,
    finalized_by bigint,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.clearances OWNER TO ogami;

--
-- Name: clearances_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.clearances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.clearances_id_seq OWNER TO ogami;

--
-- Name: clearances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.clearances_id_seq OWNED BY public.clearances.id;


--
-- Name: collections; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.collections (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    cash_account_id bigint NOT NULL,
    collection_date date NOT NULL,
    amount numeric(15,2) NOT NULL,
    payment_method character varying(30) NOT NULL,
    reference_number character varying(50),
    journal_entry_id bigint,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.collections OWNER TO ogami;

--
-- Name: collections_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.collections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.collections_id_seq OWNER TO ogami;

--
-- Name: collections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.collections_id_seq OWNED BY public.collections.id;


--
-- Name: complaint_8d_reports; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.complaint_8d_reports (
    id bigint NOT NULL,
    complaint_id bigint NOT NULL,
    d1_team text,
    d2_problem text,
    d3_containment text,
    d4_root_cause text,
    d5_corrective_action text,
    d6_verification text,
    d7_prevention text,
    d8_recognition text,
    finalized_by bigint,
    finalized_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.complaint_8d_reports OWNER TO ogami;

--
-- Name: complaint_8d_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.complaint_8d_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.complaint_8d_reports_id_seq OWNER TO ogami;

--
-- Name: complaint_8d_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.complaint_8d_reports_id_seq OWNED BY public.complaint_8d_reports.id;


--
-- Name: customer_complaints; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.customer_complaints (
    id bigint NOT NULL,
    complaint_number character varying(32) NOT NULL,
    customer_id bigint NOT NULL,
    product_id bigint,
    sales_order_id bigint,
    received_date date NOT NULL,
    severity character varying(10) NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    description text NOT NULL,
    affected_quantity integer DEFAULT 0 NOT NULL,
    ncr_id bigint,
    replacement_work_order_id bigint,
    credit_memo_id bigint,
    created_by bigint NOT NULL,
    assigned_to bigint,
    resolved_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.customer_complaints OWNER TO ogami;

--
-- Name: customer_complaints_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.customer_complaints_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_complaints_id_seq OWNER TO ogami;

--
-- Name: customer_complaints_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.customer_complaints_id_seq OWNED BY public.customer_complaints.id;


--
-- Name: customers; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.customers (
    id bigint NOT NULL,
    name character varying(200) NOT NULL,
    contact_person character varying(100),
    email character varying(200),
    phone character varying(20),
    address text,
    tin text,
    credit_limit numeric(15,2),
    payment_terms_days smallint DEFAULT '30'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.customers OWNER TO ogami;

--
-- Name: customers_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customers_id_seq OWNER TO ogami;

--
-- Name: customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.customers_id_seq OWNED BY public.customers.id;


--
-- Name: defect_types; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.defect_types (
    id bigint NOT NULL,
    code character varying(10) NOT NULL,
    name character varying(100) NOT NULL,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.defect_types OWNER TO ogami;

--
-- Name: defect_types_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.defect_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.defect_types_id_seq OWNER TO ogami;

--
-- Name: defect_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.defect_types_id_seq OWNED BY public.defect_types.id;


--
-- Name: deliveries; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.deliveries (
    id bigint NOT NULL,
    delivery_number character varying(32) NOT NULL,
    sales_order_id bigint NOT NULL,
    vehicle_id bigint,
    driver_id bigint,
    status character varying(20) DEFAULT 'scheduled'::character varying NOT NULL,
    scheduled_date date NOT NULL,
    departed_at timestamp(0) without time zone,
    delivered_at timestamp(0) without time zone,
    confirmed_at timestamp(0) without time zone,
    confirmed_by bigint,
    receipt_photo_path character varying(500),
    invoice_id bigint,
    notes text,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.deliveries OWNER TO ogami;

--
-- Name: deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.deliveries_id_seq OWNER TO ogami;

--
-- Name: deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.deliveries_id_seq OWNED BY public.deliveries.id;


--
-- Name: delivery_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.delivery_items (
    id bigint NOT NULL,
    delivery_id bigint NOT NULL,
    sales_order_item_id bigint NOT NULL,
    inspection_id bigint,
    quantity numeric(14,3) NOT NULL,
    unit_price numeric(15,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.delivery_items OWNER TO ogami;

--
-- Name: delivery_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.delivery_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.delivery_items_id_seq OWNER TO ogami;

--
-- Name: delivery_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.delivery_items_id_seq OWNED BY public.delivery_items.id;


--
-- Name: departments; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.departments (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    code character varying(20) NOT NULL,
    parent_id bigint,
    head_employee_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.departments OWNER TO ogami;

--
-- Name: departments_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.departments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.departments_id_seq OWNER TO ogami;

--
-- Name: departments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.departments_id_seq OWNED BY public.departments.id;


--
-- Name: document_sequences; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.document_sequences (
    id bigint NOT NULL,
    document_type character varying(30) NOT NULL,
    prefix character varying(10) NOT NULL,
    year smallint NOT NULL,
    month smallint NOT NULL,
    last_number bigint DEFAULT '0'::bigint NOT NULL
);


ALTER TABLE public.document_sequences OWNER TO ogami;

--
-- Name: document_sequences_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.document_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.document_sequences_id_seq OWNER TO ogami;

--
-- Name: document_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.document_sequences_id_seq OWNED BY public.document_sequences.id;


--
-- Name: employee_documents; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employee_documents (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    document_type character varying(50) NOT NULL,
    file_name character varying(200) NOT NULL,
    file_path character varying(500) NOT NULL,
    uploaded_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.employee_documents OWNER TO ogami;

--
-- Name: employee_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employee_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_documents_id_seq OWNER TO ogami;

--
-- Name: employee_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employee_documents_id_seq OWNED BY public.employee_documents.id;


--
-- Name: employee_leave_balances; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employee_leave_balances (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    leave_type_id bigint NOT NULL,
    year integer NOT NULL,
    total_credits numeric(5,1) NOT NULL,
    used numeric(5,1) DEFAULT '0'::numeric NOT NULL,
    remaining numeric(5,1) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.employee_leave_balances OWNER TO ogami;

--
-- Name: employee_leave_balances_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employee_leave_balances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_leave_balances_id_seq OWNER TO ogami;

--
-- Name: employee_leave_balances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employee_leave_balances_id_seq OWNED BY public.employee_leave_balances.id;


--
-- Name: employee_loans; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employee_loans (
    id bigint NOT NULL,
    loan_no character varying(20) NOT NULL,
    employee_id bigint NOT NULL,
    loan_type character varying(20) NOT NULL,
    principal numeric(15,2) NOT NULL,
    interest_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    monthly_amortization numeric(15,2) NOT NULL,
    total_paid numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    balance numeric(15,2) NOT NULL,
    start_date date,
    end_date date,
    pay_periods_total integer NOT NULL,
    pay_periods_remaining integer NOT NULL,
    approval_chain_size integer DEFAULT 0 NOT NULL,
    purpose text,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    is_final_pay_deduction boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.employee_loans OWNER TO ogami;

--
-- Name: employee_loans_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employee_loans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_loans_id_seq OWNER TO ogami;

--
-- Name: employee_loans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employee_loans_id_seq OWNED BY public.employee_loans.id;


--
-- Name: employee_property; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employee_property (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    item_name character varying(200) NOT NULL,
    description text,
    quantity integer DEFAULT 1 NOT NULL,
    date_issued date NOT NULL,
    date_returned date,
    status character varying(20) DEFAULT 'issued'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.employee_property OWNER TO ogami;

--
-- Name: employee_property_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employee_property_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_property_id_seq OWNER TO ogami;

--
-- Name: employee_property_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employee_property_id_seq OWNED BY public.employee_property.id;


--
-- Name: employee_shift_assignments; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employee_shift_assignments (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    shift_id bigint NOT NULL,
    effective_date date NOT NULL,
    end_date date,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.employee_shift_assignments OWNER TO ogami;

--
-- Name: employee_shift_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employee_shift_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_shift_assignments_id_seq OWNER TO ogami;

--
-- Name: employee_shift_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employee_shift_assignments_id_seq OWNED BY public.employee_shift_assignments.id;


--
-- Name: employees; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employees (
    id bigint NOT NULL,
    employee_no character varying(20) NOT NULL,
    first_name character varying(100) NOT NULL,
    middle_name character varying(100),
    last_name character varying(100) NOT NULL,
    suffix character varying(20),
    birth_date date NOT NULL,
    gender character varying(10) NOT NULL,
    civil_status character varying(20) NOT NULL,
    nationality character varying(50) DEFAULT 'Filipino'::character varying NOT NULL,
    photo_path character varying(255),
    street_address character varying(200),
    barangay character varying(100),
    city character varying(100),
    province character varying(100),
    zip_code character varying(10),
    mobile_number character varying(20),
    email character varying(255),
    emergency_contact_name character varying(100),
    emergency_contact_relation character varying(50),
    emergency_contact_phone character varying(20),
    sss_no text,
    philhealth_no text,
    pagibig_no text,
    tin text,
    department_id bigint NOT NULL,
    position_id bigint NOT NULL,
    employment_type character varying(20) NOT NULL,
    pay_type character varying(10) NOT NULL,
    date_hired date NOT NULL,
    date_regularized date,
    basic_monthly_salary numeric(15,2),
    daily_rate numeric(15,2),
    bank_name character varying(100),
    bank_account_no text,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.employees OWNER TO ogami;

--
-- Name: employees_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employees_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employees_id_seq OWNER TO ogami;

--
-- Name: employees_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employees_id_seq OWNED BY public.employees.id;


--
-- Name: employment_history; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.employment_history (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    change_type character varying(30) NOT NULL,
    from_value json,
    to_value json NOT NULL,
    effective_date date NOT NULL,
    remarks text,
    approved_by bigint,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.employment_history OWNER TO ogami;

--
-- Name: employment_history_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.employment_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employment_history_id_seq OWNER TO ogami;

--
-- Name: employment_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.employment_history_id_seq OWNED BY public.employment_history.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO ogami;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO ogami;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: goods_receipt_notes; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.goods_receipt_notes (
    id bigint NOT NULL,
    grn_number character varying(20) NOT NULL,
    purchase_order_id bigint NOT NULL,
    vendor_id bigint NOT NULL,
    received_date date NOT NULL,
    received_by bigint NOT NULL,
    status character varying(20) DEFAULT 'pending_qc'::character varying NOT NULL,
    qc_inspection_id bigint,
    accepted_by bigint,
    accepted_at timestamp(0) without time zone,
    rejected_reason text,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.goods_receipt_notes OWNER TO ogami;

--
-- Name: goods_receipt_notes_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.goods_receipt_notes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.goods_receipt_notes_id_seq OWNER TO ogami;

--
-- Name: goods_receipt_notes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.goods_receipt_notes_id_seq OWNED BY public.goods_receipt_notes.id;


--
-- Name: government_contribution_tables; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.government_contribution_tables (
    id bigint NOT NULL,
    agency character varying(20) NOT NULL,
    bracket_min numeric(15,2) NOT NULL,
    bracket_max numeric(15,2) NOT NULL,
    ee_amount numeric(15,4) NOT NULL,
    er_amount numeric(15,4) NOT NULL,
    effective_date date NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.government_contribution_tables OWNER TO ogami;

--
-- Name: government_contribution_tables_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.government_contribution_tables_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.government_contribution_tables_id_seq OWNER TO ogami;

--
-- Name: government_contribution_tables_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.government_contribution_tables_id_seq OWNED BY public.government_contribution_tables.id;


--
-- Name: grn_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.grn_items (
    id bigint NOT NULL,
    goods_receipt_note_id bigint NOT NULL,
    purchase_order_item_id bigint NOT NULL,
    item_id bigint NOT NULL,
    location_id bigint NOT NULL,
    quantity_received numeric(15,3) NOT NULL,
    quantity_accepted numeric(15,3) DEFAULT '0'::numeric NOT NULL,
    unit_cost numeric(15,4) NOT NULL,
    remarks character varying(200),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.grn_items OWNER TO ogami;

--
-- Name: grn_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.grn_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.grn_items_id_seq OWNER TO ogami;

--
-- Name: grn_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.grn_items_id_seq OWNED BY public.grn_items.id;


--
-- Name: holidays; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.holidays (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    date date NOT NULL,
    type character varying(30) NOT NULL,
    is_recurring boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.holidays OWNER TO ogami;

--
-- Name: holidays_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.holidays_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.holidays_id_seq OWNER TO ogami;

--
-- Name: holidays_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.holidays_id_seq OWNED BY public.holidays.id;


--
-- Name: inspection_measurements; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.inspection_measurements (
    id bigint NOT NULL,
    inspection_id bigint NOT NULL,
    inspection_spec_item_id bigint,
    sample_index integer DEFAULT 1 NOT NULL,
    parameter_name character varying(150) NOT NULL,
    parameter_type character varying(20) NOT NULL,
    unit_of_measure character varying(20),
    nominal_value numeric(12,4),
    tolerance_min numeric(12,4),
    tolerance_max numeric(12,4),
    measured_value numeric(12,4),
    is_critical boolean DEFAULT false NOT NULL,
    is_pass boolean,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.inspection_measurements OWNER TO ogami;

--
-- Name: inspection_measurements_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.inspection_measurements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inspection_measurements_id_seq OWNER TO ogami;

--
-- Name: inspection_measurements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.inspection_measurements_id_seq OWNED BY public.inspection_measurements.id;


--
-- Name: inspection_spec_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.inspection_spec_items (
    id bigint NOT NULL,
    inspection_spec_id bigint NOT NULL,
    parameter_name character varying(150) NOT NULL,
    parameter_type character varying(20) DEFAULT 'dimensional'::character varying NOT NULL,
    unit_of_measure character varying(20),
    nominal_value numeric(12,4),
    tolerance_min numeric(12,4),
    tolerance_max numeric(12,4),
    is_critical boolean DEFAULT false NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.inspection_spec_items OWNER TO ogami;

--
-- Name: inspection_spec_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.inspection_spec_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inspection_spec_items_id_seq OWNER TO ogami;

--
-- Name: inspection_spec_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.inspection_spec_items_id_seq OWNED BY public.inspection_spec_items.id;


--
-- Name: inspection_specs; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.inspection_specs (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    version smallint DEFAULT '1'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    notes text,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.inspection_specs OWNER TO ogami;

--
-- Name: inspection_specs_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.inspection_specs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inspection_specs_id_seq OWNER TO ogami;

--
-- Name: inspection_specs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.inspection_specs_id_seq OWNED BY public.inspection_specs.id;


--
-- Name: inspections; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.inspections (
    id bigint NOT NULL,
    inspection_number character varying(32) NOT NULL,
    stage character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    product_id bigint NOT NULL,
    inspection_spec_id bigint,
    entity_type character varying(30),
    entity_id bigint,
    batch_quantity integer NOT NULL,
    sample_size integer NOT NULL,
    aql_code character varying(4),
    accept_count integer DEFAULT 0 NOT NULL,
    reject_count integer DEFAULT 0 NOT NULL,
    defect_count integer DEFAULT 0 NOT NULL,
    inspector_id bigint,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.inspections OWNER TO ogami;

--
-- Name: inspections_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.inspections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inspections_id_seq OWNER TO ogami;

--
-- Name: inspections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.inspections_id_seq OWNED BY public.inspections.id;


--
-- Name: invoice_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.invoice_items (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    revenue_account_id bigint NOT NULL,
    product_id bigint,
    description character varying(200) NOT NULL,
    quantity numeric(12,2) NOT NULL,
    unit character varying(20),
    unit_price numeric(15,2) NOT NULL,
    total numeric(15,2) NOT NULL
);


ALTER TABLE public.invoice_items OWNER TO ogami;

--
-- Name: invoice_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.invoice_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.invoice_items_id_seq OWNER TO ogami;

--
-- Name: invoice_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.invoice_items_id_seq OWNED BY public.invoice_items.id;


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.invoices (
    id bigint NOT NULL,
    invoice_number character varying(30) NOT NULL,
    customer_id bigint NOT NULL,
    sales_order_id bigint,
    delivery_id bigint,
    date date NOT NULL,
    due_date date NOT NULL,
    is_vatable boolean DEFAULT true NOT NULL,
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    vat_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    amount_paid numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    balance numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    journal_entry_id bigint,
    created_by bigint,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.invoices OWNER TO ogami;

--
-- Name: invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.invoices_id_seq OWNER TO ogami;

--
-- Name: invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.invoices_id_seq OWNED BY public.invoices.id;


--
-- Name: item_categories; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.item_categories (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    parent_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.item_categories OWNER TO ogami;

--
-- Name: item_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.item_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.item_categories_id_seq OWNER TO ogami;

--
-- Name: item_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.item_categories_id_seq OWNED BY public.item_categories.id;


--
-- Name: items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.items (
    id bigint NOT NULL,
    code character varying(30) NOT NULL,
    name character varying(200) NOT NULL,
    description text,
    category_id bigint NOT NULL,
    item_type character varying(20) NOT NULL,
    unit_of_measure character varying(20) NOT NULL,
    standard_cost numeric(15,4) DEFAULT '0'::numeric NOT NULL,
    reorder_method character varying(20) DEFAULT 'fixed_quantity'::character varying NOT NULL,
    reorder_point numeric(15,3) DEFAULT '0'::numeric NOT NULL,
    safety_stock numeric(15,3) DEFAULT '0'::numeric NOT NULL,
    minimum_order_quantity numeric(15,3) DEFAULT '1'::numeric NOT NULL,
    lead_time_days smallint DEFAULT '0'::smallint NOT NULL,
    is_critical boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.items OWNER TO ogami;

--
-- Name: items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.items_id_seq OWNER TO ogami;

--
-- Name: items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.items_id_seq OWNED BY public.items.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO ogami;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO ogami;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO ogami;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: journal_entries; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.journal_entries (
    id bigint NOT NULL,
    entry_number character varying(30) NOT NULL,
    date date NOT NULL,
    description text,
    reference_type character varying(50),
    reference_id bigint,
    total_debit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_credit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    posted_at timestamp(0) without time zone,
    posted_by bigint,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    reversed_by_entry_id bigint
);


ALTER TABLE public.journal_entries OWNER TO ogami;

--
-- Name: journal_entries_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.journal_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.journal_entries_id_seq OWNER TO ogami;

--
-- Name: journal_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.journal_entries_id_seq OWNED BY public.journal_entries.id;


--
-- Name: journal_entry_lines; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.journal_entry_lines (
    id bigint NOT NULL,
    journal_entry_id bigint NOT NULL,
    account_id bigint NOT NULL,
    line_no integer NOT NULL,
    debit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    credit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    description character varying(255),
    CONSTRAINT jel_debit_xor_credit_chk CHECK ((((debit > (0)::numeric) AND (credit = (0)::numeric)) OR ((debit = (0)::numeric) AND (credit > (0)::numeric))))
);


ALTER TABLE public.journal_entry_lines OWNER TO ogami;

--
-- Name: journal_entry_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.journal_entry_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.journal_entry_lines_id_seq OWNER TO ogami;

--
-- Name: journal_entry_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.journal_entry_lines_id_seq OWNED BY public.journal_entry_lines.id;


--
-- Name: leave_requests; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.leave_requests (
    id bigint NOT NULL,
    leave_request_no character varying(20) NOT NULL,
    employee_id bigint NOT NULL,
    leave_type_id bigint NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    days numeric(4,1) NOT NULL,
    reason text,
    document_path character varying(255),
    status character varying(20) DEFAULT 'pending_dept'::character varying NOT NULL,
    dept_approver_id bigint,
    dept_approved_at timestamp(0) without time zone,
    hr_approver_id bigint,
    hr_approved_at timestamp(0) without time zone,
    rejection_reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.leave_requests OWNER TO ogami;

--
-- Name: leave_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.leave_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.leave_requests_id_seq OWNER TO ogami;

--
-- Name: leave_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.leave_requests_id_seq OWNED BY public.leave_requests.id;


--
-- Name: leave_types; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.leave_types (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    code character varying(10) NOT NULL,
    default_balance numeric(5,1) NOT NULL,
    is_paid boolean DEFAULT true NOT NULL,
    requires_document boolean DEFAULT false NOT NULL,
    is_convertible_on_separation boolean DEFAULT false NOT NULL,
    is_convertible_year_end boolean DEFAULT false NOT NULL,
    conversion_rate numeric(3,2) DEFAULT '1'::numeric NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.leave_types OWNER TO ogami;

--
-- Name: leave_types_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.leave_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.leave_types_id_seq OWNER TO ogami;

--
-- Name: leave_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.leave_types_id_seq OWNED BY public.leave_types.id;


--
-- Name: loan_payments; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.loan_payments (
    id bigint NOT NULL,
    loan_id bigint NOT NULL,
    payroll_id bigint,
    amount numeric(15,2) NOT NULL,
    payment_date date NOT NULL,
    payment_type character varying(20) DEFAULT 'payroll_deduction'::character varying NOT NULL,
    remarks character varying(255),
    created_at timestamp(0) without time zone
);


ALTER TABLE public.loan_payments OWNER TO ogami;

--
-- Name: loan_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.loan_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.loan_payments_id_seq OWNER TO ogami;

--
-- Name: loan_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.loan_payments_id_seq OWNED BY public.loan_payments.id;


--
-- Name: machine_downtimes; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.machine_downtimes (
    id bigint NOT NULL,
    machine_id bigint NOT NULL,
    work_order_id bigint,
    start_time timestamp(0) without time zone NOT NULL,
    end_time timestamp(0) without time zone,
    duration_minutes integer,
    category character varying(30) NOT NULL,
    description text,
    maintenance_order_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.machine_downtimes OWNER TO ogami;

--
-- Name: machine_downtimes_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.machine_downtimes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.machine_downtimes_id_seq OWNER TO ogami;

--
-- Name: machine_downtimes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.machine_downtimes_id_seq OWNED BY public.machine_downtimes.id;


--
-- Name: machines; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.machines (
    id bigint NOT NULL,
    machine_code character varying(20) NOT NULL,
    name character varying(100) NOT NULL,
    tonnage smallint,
    machine_type character varying(50) DEFAULT 'injection_molder'::character varying NOT NULL,
    operators_required numeric(3,1) DEFAULT '1'::numeric NOT NULL,
    available_hours_per_day numeric(4,1) DEFAULT '16'::numeric NOT NULL,
    status character varying(20) DEFAULT 'idle'::character varying NOT NULL,
    current_work_order_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    asset_id bigint
);


ALTER TABLE public.machines OWNER TO ogami;

--
-- Name: machines_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.machines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.machines_id_seq OWNER TO ogami;

--
-- Name: machines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.machines_id_seq OWNED BY public.machines.id;


--
-- Name: maintenance_logs; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.maintenance_logs (
    id bigint NOT NULL,
    work_order_id bigint NOT NULL,
    description text NOT NULL,
    logged_by bigint NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.maintenance_logs OWNER TO ogami;

--
-- Name: maintenance_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.maintenance_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.maintenance_logs_id_seq OWNER TO ogami;

--
-- Name: maintenance_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.maintenance_logs_id_seq OWNED BY public.maintenance_logs.id;


--
-- Name: maintenance_schedules; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.maintenance_schedules (
    id bigint NOT NULL,
    maintainable_type character varying(50) NOT NULL,
    maintainable_id bigint NOT NULL,
    schedule_type character varying(20) DEFAULT 'preventive'::character varying NOT NULL,
    description character varying(200) NOT NULL,
    interval_type character varying(20) NOT NULL,
    interval_value integer NOT NULL,
    last_performed_at timestamp(0) without time zone,
    next_due_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.maintenance_schedules OWNER TO ogami;

--
-- Name: maintenance_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.maintenance_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.maintenance_schedules_id_seq OWNER TO ogami;

--
-- Name: maintenance_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.maintenance_schedules_id_seq OWNED BY public.maintenance_schedules.id;


--
-- Name: maintenance_work_orders; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.maintenance_work_orders (
    id bigint NOT NULL,
    mwo_number character varying(32) NOT NULL,
    maintainable_type character varying(50) NOT NULL,
    maintainable_id bigint NOT NULL,
    schedule_id bigint,
    type character varying(20) NOT NULL,
    priority character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    description text NOT NULL,
    assigned_to bigint,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    downtime_minutes integer DEFAULT 0 NOT NULL,
    cost numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    remarks text,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.maintenance_work_orders OWNER TO ogami;

--
-- Name: maintenance_work_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.maintenance_work_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.maintenance_work_orders_id_seq OWNER TO ogami;

--
-- Name: maintenance_work_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.maintenance_work_orders_id_seq OWNED BY public.maintenance_work_orders.id;


--
-- Name: material_issue_slip_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.material_issue_slip_items (
    id bigint NOT NULL,
    material_issue_slip_id bigint NOT NULL,
    item_id bigint NOT NULL,
    location_id bigint NOT NULL,
    quantity_issued numeric(15,3) NOT NULL,
    unit_cost numeric(15,4) NOT NULL,
    total_cost numeric(15,2) NOT NULL,
    material_reservation_id bigint,
    remarks character varying(200),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.material_issue_slip_items OWNER TO ogami;

--
-- Name: material_issue_slip_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.material_issue_slip_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.material_issue_slip_items_id_seq OWNER TO ogami;

--
-- Name: material_issue_slip_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.material_issue_slip_items_id_seq OWNED BY public.material_issue_slip_items.id;


--
-- Name: material_issue_slips; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.material_issue_slips (
    id bigint NOT NULL,
    slip_number character varying(20) NOT NULL,
    work_order_id bigint,
    issued_date date NOT NULL,
    issued_by bigint NOT NULL,
    created_by bigint NOT NULL,
    status character varying(20) DEFAULT 'issued'::character varying NOT NULL,
    total_value numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    reference_text text,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.material_issue_slips OWNER TO ogami;

--
-- Name: material_issue_slips_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.material_issue_slips_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.material_issue_slips_id_seq OWNER TO ogami;

--
-- Name: material_issue_slips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.material_issue_slips_id_seq OWNED BY public.material_issue_slips.id;


--
-- Name: material_reservations; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.material_reservations (
    id bigint NOT NULL,
    item_id bigint NOT NULL,
    work_order_id bigint,
    location_id bigint,
    quantity numeric(15,3) NOT NULL,
    status character varying(20) DEFAULT 'reserved'::character varying NOT NULL,
    reserved_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    released_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.material_reservations OWNER TO ogami;

--
-- Name: material_reservations_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.material_reservations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.material_reservations_id_seq OWNER TO ogami;

--
-- Name: material_reservations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.material_reservations_id_seq OWNED BY public.material_reservations.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO ogami;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO ogami;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: mold_history; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.mold_history (
    id bigint NOT NULL,
    mold_id bigint NOT NULL,
    event_type character varying(30) NOT NULL,
    description text,
    cost numeric(15,2),
    performed_by character varying(100),
    event_date date NOT NULL,
    shot_count_at_event integer NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.mold_history OWNER TO ogami;

--
-- Name: mold_history_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.mold_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.mold_history_id_seq OWNER TO ogami;

--
-- Name: mold_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.mold_history_id_seq OWNED BY public.mold_history.id;


--
-- Name: mold_machine_compatibility; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.mold_machine_compatibility (
    mold_id bigint NOT NULL,
    machine_id bigint NOT NULL
);


ALTER TABLE public.mold_machine_compatibility OWNER TO ogami;

--
-- Name: molds; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.molds (
    id bigint NOT NULL,
    mold_code character varying(20) NOT NULL,
    name character varying(100) NOT NULL,
    product_id bigint NOT NULL,
    cavity_count smallint NOT NULL,
    cycle_time_seconds smallint NOT NULL,
    output_rate_per_hour integer NOT NULL,
    setup_time_minutes smallint DEFAULT '90'::smallint NOT NULL,
    current_shot_count integer DEFAULT 0 NOT NULL,
    max_shots_before_maintenance integer NOT NULL,
    lifetime_total_shots integer DEFAULT 0 NOT NULL,
    lifetime_max_shots integer NOT NULL,
    status character varying(20) DEFAULT 'available'::character varying NOT NULL,
    location character varying(50),
    asset_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.molds OWNER TO ogami;

--
-- Name: molds_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.molds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.molds_id_seq OWNER TO ogami;

--
-- Name: molds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.molds_id_seq OWNED BY public.molds.id;


--
-- Name: mrp_plans; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.mrp_plans (
    id bigint NOT NULL,
    mrp_plan_no character varying(20) NOT NULL,
    sales_order_id bigint NOT NULL,
    version smallint DEFAULT '1'::smallint NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    generated_by bigint NOT NULL,
    total_lines integer DEFAULT 0 NOT NULL,
    shortages_found integer DEFAULT 0 NOT NULL,
    auto_pr_count integer DEFAULT 0 NOT NULL,
    draft_wo_count integer DEFAULT 0 NOT NULL,
    diagnostics json,
    generated_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.mrp_plans OWNER TO ogami;

--
-- Name: mrp_plans_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.mrp_plans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.mrp_plans_id_seq OWNER TO ogami;

--
-- Name: mrp_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.mrp_plans_id_seq OWNED BY public.mrp_plans.id;


--
-- Name: ncr_actions; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.ncr_actions (
    id bigint NOT NULL,
    ncr_id bigint NOT NULL,
    action_type character varying(20) NOT NULL,
    description text NOT NULL,
    performed_by bigint NOT NULL,
    performed_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ncr_actions OWNER TO ogami;

--
-- Name: ncr_actions_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.ncr_actions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ncr_actions_id_seq OWNER TO ogami;

--
-- Name: ncr_actions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.ncr_actions_id_seq OWNED BY public.ncr_actions.id;


--
-- Name: non_conformance_reports; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.non_conformance_reports (
    id bigint NOT NULL,
    ncr_number character varying(32) NOT NULL,
    source character varying(30) NOT NULL,
    severity character varying(10) NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    product_id bigint,
    inspection_id bigint,
    complaint_id bigint,
    defect_description text NOT NULL,
    affected_quantity integer DEFAULT 0 NOT NULL,
    disposition character varying(30),
    root_cause text,
    corrective_action text,
    created_by bigint NOT NULL,
    assigned_to bigint,
    closed_by bigint,
    closed_at timestamp(0) without time zone,
    replacement_work_order_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.non_conformance_reports OWNER TO ogami;

--
-- Name: non_conformance_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.non_conformance_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.non_conformance_reports_id_seq OWNER TO ogami;

--
-- Name: non_conformance_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.non_conformance_reports_id_seq OWNED BY public.non_conformance_reports.id;


--
-- Name: notification_preferences; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.notification_preferences (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    notification_type character varying(100) NOT NULL,
    channel character varying(20) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notification_preferences OWNER TO ogami;

--
-- Name: notification_preferences_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.notification_preferences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notification_preferences_id_seq OWNER TO ogami;

--
-- Name: notification_preferences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.notification_preferences_id_seq OWNED BY public.notification_preferences.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    notifiable_id bigint NOT NULL,
    data json NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notifications OWNER TO ogami;

--
-- Name: overtime_requests; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.overtime_requests (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    date date NOT NULL,
    hours_requested numeric(3,1) NOT NULL,
    reason text NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    approved_by bigint,
    approved_at timestamp(0) without time zone,
    rejection_reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.overtime_requests OWNER TO ogami;

--
-- Name: overtime_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.overtime_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.overtime_requests_id_seq OWNER TO ogami;

--
-- Name: overtime_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.overtime_requests_id_seq OWNED BY public.overtime_requests.id;


--
-- Name: password_history; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.password_history (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    password_hash character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.password_history OWNER TO ogami;

--
-- Name: password_history_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.password_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.password_history_id_seq OWNER TO ogami;

--
-- Name: password_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.password_history_id_seq OWNED BY public.password_history.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO ogami;

--
-- Name: payroll_adjustments; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.payroll_adjustments (
    id bigint NOT NULL,
    payroll_period_id bigint NOT NULL,
    employee_id bigint NOT NULL,
    original_payroll_id bigint NOT NULL,
    type character varying(20) NOT NULL,
    amount numeric(15,2) NOT NULL,
    reason text NOT NULL,
    approved_by bigint,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    applied_at timestamp(0) without time zone,
    applied_to_payroll_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.payroll_adjustments OWNER TO ogami;

--
-- Name: payroll_adjustments_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.payroll_adjustments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payroll_adjustments_id_seq OWNER TO ogami;

--
-- Name: payroll_adjustments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.payroll_adjustments_id_seq OWNED BY public.payroll_adjustments.id;


--
-- Name: payroll_deduction_details; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.payroll_deduction_details (
    id bigint NOT NULL,
    payroll_id bigint NOT NULL,
    deduction_type character varying(30) NOT NULL,
    description character varying(200),
    amount numeric(15,2) NOT NULL,
    reference_id bigint
);


ALTER TABLE public.payroll_deduction_details OWNER TO ogami;

--
-- Name: payroll_deduction_details_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.payroll_deduction_details_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payroll_deduction_details_id_seq OWNER TO ogami;

--
-- Name: payroll_deduction_details_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.payroll_deduction_details_id_seq OWNED BY public.payroll_deduction_details.id;


--
-- Name: payroll_periods; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.payroll_periods (
    id bigint NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    payroll_date date NOT NULL,
    is_first_half boolean DEFAULT true NOT NULL,
    is_thirteenth_month boolean DEFAULT false NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    journal_entry_id bigint,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.payroll_periods OWNER TO ogami;

--
-- Name: payroll_periods_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.payroll_periods_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payroll_periods_id_seq OWNER TO ogami;

--
-- Name: payroll_periods_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.payroll_periods_id_seq OWNED BY public.payroll_periods.id;


--
-- Name: payrolls; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.payrolls (
    id bigint NOT NULL,
    payroll_period_id bigint NOT NULL,
    employee_id bigint NOT NULL,
    pay_type character varying(10) NOT NULL,
    days_worked numeric(4,1),
    basic_pay numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    overtime_pay numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    night_diff_pay numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    holiday_pay numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    gross_pay numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    sss_ee numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    sss_er numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    philhealth_ee numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    philhealth_er numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    pagibig_ee numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    pagibig_er numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    withholding_tax numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    loan_deductions numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    other_deductions numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    adjustment_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_deductions numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    net_pay numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    error_message text,
    computed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.payrolls OWNER TO ogami;

--
-- Name: payrolls_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.payrolls_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payrolls_id_seq OWNER TO ogami;

--
-- Name: payrolls_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.payrolls_id_seq OWNED BY public.payrolls.id;


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    slug character varying(100) NOT NULL,
    module character varying(50) NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.permissions OWNER TO ogami;

--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.permissions_id_seq OWNER TO ogami;

--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: positions; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.positions (
    id bigint NOT NULL,
    title character varying(100) NOT NULL,
    department_id bigint NOT NULL,
    salary_grade character varying(20),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.positions OWNER TO ogami;

--
-- Name: positions_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.positions_id_seq OWNER TO ogami;

--
-- Name: positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.positions_id_seq OWNED BY public.positions.id;


--
-- Name: product_price_agreements; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.product_price_agreements (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    customer_id bigint NOT NULL,
    price numeric(15,2) NOT NULL,
    effective_from date NOT NULL,
    effective_to date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_price_agreements OWNER TO ogami;

--
-- Name: product_price_agreements_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.product_price_agreements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_price_agreements_id_seq OWNER TO ogami;

--
-- Name: product_price_agreements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.product_price_agreements_id_seq OWNED BY public.product_price_agreements.id;


--
-- Name: production_schedules; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.production_schedules (
    id bigint NOT NULL,
    work_order_id bigint NOT NULL,
    machine_id bigint NOT NULL,
    mold_id bigint NOT NULL,
    scheduled_start timestamp(0) without time zone NOT NULL,
    scheduled_end timestamp(0) without time zone NOT NULL,
    priority_order smallint NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    is_confirmed boolean DEFAULT false NOT NULL,
    confirmed_by bigint,
    confirmed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.production_schedules OWNER TO ogami;

--
-- Name: production_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.production_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.production_schedules_id_seq OWNER TO ogami;

--
-- Name: production_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.production_schedules_id_seq OWNED BY public.production_schedules.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.products (
    id bigint NOT NULL,
    part_number character varying(30) NOT NULL,
    name character varying(200) NOT NULL,
    description text,
    unit_of_measure character varying(20) DEFAULT 'pcs'::character varying NOT NULL,
    standard_cost numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.products OWNER TO ogami;

--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.products_id_seq OWNER TO ogami;

--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: purchase_order_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.purchase_order_items (
    id bigint NOT NULL,
    purchase_order_id bigint NOT NULL,
    item_id bigint NOT NULL,
    purchase_request_item_id bigint,
    description character varying(200) NOT NULL,
    quantity numeric(12,2) NOT NULL,
    unit character varying(20),
    unit_price numeric(15,2) NOT NULL,
    total numeric(15,2) NOT NULL,
    quantity_received numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.purchase_order_items OWNER TO ogami;

--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.purchase_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.purchase_order_items_id_seq OWNER TO ogami;

--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.purchase_order_items_id_seq OWNED BY public.purchase_order_items.id;


--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.purchase_orders (
    id bigint NOT NULL,
    po_number character varying(20) NOT NULL,
    vendor_id bigint NOT NULL,
    purchase_request_id bigint,
    date date NOT NULL,
    expected_delivery_date date,
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    vat_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    is_vatable boolean DEFAULT true NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    requires_vp_approval boolean DEFAULT false NOT NULL,
    current_approval_step smallint DEFAULT '0'::smallint NOT NULL,
    approved_by bigint,
    approved_at timestamp(0) without time zone,
    sent_to_supplier_at timestamp(0) without time zone,
    created_by bigint,
    remarks text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.purchase_orders OWNER TO ogami;

--
-- Name: purchase_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.purchase_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.purchase_orders_id_seq OWNER TO ogami;

--
-- Name: purchase_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.purchase_orders_id_seq OWNED BY public.purchase_orders.id;


--
-- Name: purchase_request_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.purchase_request_items (
    id bigint NOT NULL,
    purchase_request_id bigint NOT NULL,
    item_id bigint,
    description character varying(200) NOT NULL,
    quantity numeric(12,2) NOT NULL,
    unit character varying(20),
    estimated_unit_price numeric(15,2),
    purpose character varying(200),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.purchase_request_items OWNER TO ogami;

--
-- Name: purchase_request_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.purchase_request_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.purchase_request_items_id_seq OWNER TO ogami;

--
-- Name: purchase_request_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.purchase_request_items_id_seq OWNED BY public.purchase_request_items.id;


--
-- Name: purchase_requests; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.purchase_requests (
    id bigint NOT NULL,
    pr_number character varying(20) NOT NULL,
    requested_by bigint NOT NULL,
    department_id bigint,
    date date NOT NULL,
    reason text,
    priority character varying(10) DEFAULT 'normal'::character varying NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    is_auto_generated boolean DEFAULT false NOT NULL,
    current_approval_step smallint DEFAULT '0'::smallint NOT NULL,
    submitted_at timestamp(0) without time zone,
    approved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    mrp_plan_id bigint
);


ALTER TABLE public.purchase_requests OWNER TO ogami;

--
-- Name: purchase_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.purchase_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.purchase_requests_id_seq OWNER TO ogami;

--
-- Name: purchase_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.purchase_requests_id_seq OWNED BY public.purchase_requests.id;


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.role_permissions (
    role_id bigint NOT NULL,
    permission_id bigint NOT NULL
);


ALTER TABLE public.role_permissions OWNER TO ogami;

--
-- Name: roles; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(50) NOT NULL,
    slug character varying(50) NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.roles OWNER TO ogami;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_id_seq OWNER TO ogami;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sales_order_items; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.sales_order_items (
    id bigint NOT NULL,
    sales_order_id bigint NOT NULL,
    product_id bigint NOT NULL,
    quantity numeric(10,2) NOT NULL,
    unit_price numeric(15,2) NOT NULL,
    total numeric(15,2) NOT NULL,
    quantity_delivered numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    delivery_date date NOT NULL
);


ALTER TABLE public.sales_order_items OWNER TO ogami;

--
-- Name: sales_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.sales_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sales_order_items_id_seq OWNER TO ogami;

--
-- Name: sales_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.sales_order_items_id_seq OWNED BY public.sales_order_items.id;


--
-- Name: sales_orders; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.sales_orders (
    id bigint NOT NULL,
    so_number character varying(20) NOT NULL,
    customer_id bigint NOT NULL,
    date date NOT NULL,
    subtotal numeric(15,2) NOT NULL,
    vat_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    payment_terms_days smallint DEFAULT '30'::smallint NOT NULL,
    delivery_terms character varying(50),
    notes text,
    mrp_plan_id bigint,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.sales_orders OWNER TO ogami;

--
-- Name: sales_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.sales_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sales_orders_id_seq OWNER TO ogami;

--
-- Name: sales_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.sales_orders_id_seq OWNED BY public.sales_orders.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO ogami;

--
-- Name: settings; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.settings (
    id bigint NOT NULL,
    key character varying(100) NOT NULL,
    value json NOT NULL,
    "group" character varying(50) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.settings OWNER TO ogami;

--
-- Name: settings_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settings_id_seq OWNER TO ogami;

--
-- Name: settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.settings_id_seq OWNED BY public.settings.id;


--
-- Name: shifts; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.shifts (
    id bigint NOT NULL,
    name character varying(50) NOT NULL,
    start_time time(0) without time zone NOT NULL,
    end_time time(0) without time zone NOT NULL,
    break_minutes integer DEFAULT 0 NOT NULL,
    is_night_shift boolean DEFAULT false NOT NULL,
    is_extended boolean DEFAULT false NOT NULL,
    auto_ot_hours numeric(3,1),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.shifts OWNER TO ogami;

--
-- Name: shifts_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.shifts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.shifts_id_seq OWNER TO ogami;

--
-- Name: shifts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.shifts_id_seq OWNED BY public.shifts.id;


--
-- Name: shipment_documents; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.shipment_documents (
    id bigint NOT NULL,
    shipment_id bigint NOT NULL,
    document_type character varying(40) NOT NULL,
    file_path character varying(500) NOT NULL,
    original_filename character varying(255),
    file_size_bytes integer,
    mime_type character varying(100),
    notes text,
    uploaded_by bigint NOT NULL,
    uploaded_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.shipment_documents OWNER TO ogami;

--
-- Name: shipment_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.shipment_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.shipment_documents_id_seq OWNER TO ogami;

--
-- Name: shipment_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.shipment_documents_id_seq OWNED BY public.shipment_documents.id;


--
-- Name: shipments; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.shipments (
    id bigint NOT NULL,
    shipment_number character varying(32) NOT NULL,
    purchase_order_id bigint NOT NULL,
    status character varying(20) DEFAULT 'ordered'::character varying NOT NULL,
    carrier character varying(100),
    vessel character varying(100),
    container_number character varying(32),
    bl_number character varying(32),
    etd date,
    atd date,
    eta date,
    ata date,
    customs_clearance_date date,
    notes text,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.shipments OWNER TO ogami;

--
-- Name: shipments_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.shipments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.shipments_id_seq OWNER TO ogami;

--
-- Name: shipments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.shipments_id_seq OWNED BY public.shipments.id;


--
-- Name: spare_part_usage; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.spare_part_usage (
    id bigint NOT NULL,
    work_order_id bigint NOT NULL,
    item_id bigint NOT NULL,
    quantity numeric(10,2) NOT NULL,
    unit_cost numeric(15,2) NOT NULL,
    total_cost numeric(15,2) NOT NULL,
    stock_movement_id bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.spare_part_usage OWNER TO ogami;

--
-- Name: spare_part_usage_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.spare_part_usage_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.spare_part_usage_id_seq OWNER TO ogami;

--
-- Name: spare_part_usage_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.spare_part_usage_id_seq OWNED BY public.spare_part_usage.id;


--
-- Name: stock_levels; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.stock_levels (
    id bigint NOT NULL,
    item_id bigint NOT NULL,
    location_id bigint NOT NULL,
    quantity numeric(15,3) DEFAULT '0'::numeric NOT NULL,
    reserved_quantity numeric(15,3) DEFAULT '0'::numeric NOT NULL,
    weighted_avg_cost numeric(15,4) DEFAULT '0'::numeric NOT NULL,
    last_counted_at timestamp(0) without time zone,
    lock_version bigint DEFAULT '0'::bigint NOT NULL,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.stock_levels OWNER TO ogami;

--
-- Name: stock_levels_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.stock_levels_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.stock_levels_id_seq OWNER TO ogami;

--
-- Name: stock_levels_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.stock_levels_id_seq OWNED BY public.stock_levels.id;


--
-- Name: stock_movements; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.stock_movements (
    id bigint NOT NULL,
    item_id bigint NOT NULL,
    from_location_id bigint,
    to_location_id bigint,
    movement_type character varying(30) NOT NULL,
    quantity numeric(15,3) NOT NULL,
    unit_cost numeric(15,4) DEFAULT '0'::numeric NOT NULL,
    total_cost numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    reference_type character varying(50),
    reference_id bigint,
    remarks text,
    created_by bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.stock_movements OWNER TO ogami;

--
-- Name: stock_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.stock_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.stock_movements_id_seq OWNER TO ogami;

--
-- Name: stock_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.stock_movements_id_seq OWNED BY public.stock_movements.id;


--
-- Name: thirteenth_month_accruals; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.thirteenth_month_accruals (
    id bigint NOT NULL,
    employee_id bigint NOT NULL,
    year integer NOT NULL,
    total_basic_earned numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    accrued_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    is_paid boolean DEFAULT false NOT NULL,
    paid_date date,
    payroll_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.thirteenth_month_accruals OWNER TO ogami;

--
-- Name: thirteenth_month_accruals_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.thirteenth_month_accruals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.thirteenth_month_accruals_id_seq OWNER TO ogami;

--
-- Name: thirteenth_month_accruals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.thirteenth_month_accruals_id_seq OWNED BY public.thirteenth_month_accruals.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    role_id bigint NOT NULL,
    employee_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    must_change_password boolean DEFAULT false NOT NULL,
    last_activity timestamp(0) without time zone,
    password_changed_at timestamp(0) without time zone,
    failed_login_attempts smallint DEFAULT '0'::smallint NOT NULL,
    locked_until timestamp(0) without time zone,
    theme_mode character varying(10) DEFAULT 'system'::character varying NOT NULL,
    sidebar_collapsed boolean DEFAULT false NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO ogami;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO ogami;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vehicles; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.vehicles (
    id bigint NOT NULL,
    plate_number character varying(20) NOT NULL,
    name character varying(100) NOT NULL,
    vehicle_type character varying(20) NOT NULL,
    capacity_kg numeric(10,2),
    status character varying(20) DEFAULT 'available'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    asset_id bigint
);


ALTER TABLE public.vehicles OWNER TO ogami;

--
-- Name: vehicles_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.vehicles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicles_id_seq OWNER TO ogami;

--
-- Name: vehicles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.vehicles_id_seq OWNED BY public.vehicles.id;


--
-- Name: vendors; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.vendors (
    id bigint NOT NULL,
    name character varying(200) NOT NULL,
    contact_person character varying(100),
    email character varying(200),
    phone character varying(20),
    address text,
    tin text,
    payment_terms_days smallint DEFAULT '30'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.vendors OWNER TO ogami;

--
-- Name: vendors_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.vendors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vendors_id_seq OWNER TO ogami;

--
-- Name: vendors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.vendors_id_seq OWNED BY public.vendors.id;


--
-- Name: warehouse_locations; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.warehouse_locations (
    id bigint NOT NULL,
    zone_id bigint NOT NULL,
    code character varying(20) NOT NULL,
    rack character varying(10),
    bin character varying(10),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.warehouse_locations OWNER TO ogami;

--
-- Name: warehouse_locations_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.warehouse_locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.warehouse_locations_id_seq OWNER TO ogami;

--
-- Name: warehouse_locations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.warehouse_locations_id_seq OWNED BY public.warehouse_locations.id;


--
-- Name: warehouse_zones; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.warehouse_zones (
    id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    name character varying(50) NOT NULL,
    code character varying(10) NOT NULL,
    zone_type character varying(30) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.warehouse_zones OWNER TO ogami;

--
-- Name: warehouse_zones_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.warehouse_zones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.warehouse_zones_id_seq OWNER TO ogami;

--
-- Name: warehouse_zones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.warehouse_zones_id_seq OWNED BY public.warehouse_zones.id;


--
-- Name: warehouses; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.warehouses (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    code character varying(20) NOT NULL,
    address text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.warehouses OWNER TO ogami;

--
-- Name: warehouses_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.warehouses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.warehouses_id_seq OWNER TO ogami;

--
-- Name: warehouses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.warehouses_id_seq OWNED BY public.warehouses.id;


--
-- Name: work_order_defects; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.work_order_defects (
    id bigint NOT NULL,
    output_id bigint NOT NULL,
    defect_type_id bigint NOT NULL,
    count integer NOT NULL
);


ALTER TABLE public.work_order_defects OWNER TO ogami;

--
-- Name: work_order_defects_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.work_order_defects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.work_order_defects_id_seq OWNER TO ogami;

--
-- Name: work_order_defects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.work_order_defects_id_seq OWNED BY public.work_order_defects.id;


--
-- Name: work_order_materials; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.work_order_materials (
    id bigint NOT NULL,
    work_order_id bigint NOT NULL,
    item_id bigint NOT NULL,
    bom_quantity numeric(15,3) NOT NULL,
    actual_quantity_issued numeric(15,3) DEFAULT '0'::numeric NOT NULL,
    variance numeric(15,3) DEFAULT '0'::numeric NOT NULL
);


ALTER TABLE public.work_order_materials OWNER TO ogami;

--
-- Name: work_order_materials_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.work_order_materials_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.work_order_materials_id_seq OWNER TO ogami;

--
-- Name: work_order_materials_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.work_order_materials_id_seq OWNED BY public.work_order_materials.id;


--
-- Name: work_order_outputs; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.work_order_outputs (
    id bigint NOT NULL,
    work_order_id bigint NOT NULL,
    recorded_by bigint NOT NULL,
    recorded_at timestamp(0) without time zone NOT NULL,
    good_count integer NOT NULL,
    reject_count integer NOT NULL,
    shift character varying(20),
    batch_code character varying(30),
    remarks text
);


ALTER TABLE public.work_order_outputs OWNER TO ogami;

--
-- Name: work_order_outputs_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.work_order_outputs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.work_order_outputs_id_seq OWNER TO ogami;

--
-- Name: work_order_outputs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.work_order_outputs_id_seq OWNED BY public.work_order_outputs.id;


--
-- Name: work_orders; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.work_orders (
    id bigint NOT NULL,
    wo_number character varying(20) NOT NULL,
    product_id bigint NOT NULL,
    sales_order_id bigint,
    sales_order_item_id bigint,
    mrp_plan_id bigint,
    parent_wo_id bigint,
    parent_ncr_id bigint,
    machine_id bigint,
    mold_id bigint,
    quantity_target numeric(10,0) NOT NULL,
    quantity_produced numeric(10,0) DEFAULT '0'::numeric NOT NULL,
    quantity_good numeric(10,0) DEFAULT '0'::numeric NOT NULL,
    quantity_rejected numeric(10,0) DEFAULT '0'::numeric NOT NULL,
    scrap_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    planned_start timestamp(0) without time zone NOT NULL,
    planned_end timestamp(0) without time zone NOT NULL,
    actual_start timestamp(0) without time zone,
    actual_end timestamp(0) without time zone,
    status character varying(20) DEFAULT 'planned'::character varying NOT NULL,
    pause_reason character varying(200),
    priority smallint DEFAULT '0'::smallint NOT NULL,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.work_orders OWNER TO ogami;

--
-- Name: work_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.work_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.work_orders_id_seq OWNER TO ogami;

--
-- Name: work_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.work_orders_id_seq OWNED BY public.work_orders.id;


--
-- Name: workflow_definitions; Type: TABLE; Schema: public; Owner: ogami
--

CREATE TABLE public.workflow_definitions (
    id bigint NOT NULL,
    workflow_type character varying(50) NOT NULL,
    name character varying(100) NOT NULL,
    steps json NOT NULL,
    amount_threshold numeric(15,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.workflow_definitions OWNER TO ogami;

--
-- Name: workflow_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: ogami
--

CREATE SEQUENCE public.workflow_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workflow_definitions_id_seq OWNER TO ogami;

--
-- Name: workflow_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: ogami
--

ALTER SEQUENCE public.workflow_definitions_id_seq OWNED BY public.workflow_definitions.id;


--
-- Name: accounts id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.accounts ALTER COLUMN id SET DEFAULT nextval('public.accounts_id_seq'::regclass);


--
-- Name: approval_records id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approval_records ALTER COLUMN id SET DEFAULT nextval('public.approval_records_id_seq'::regclass);


--
-- Name: approved_suppliers id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approved_suppliers ALTER COLUMN id SET DEFAULT nextval('public.approved_suppliers_id_seq'::regclass);


--
-- Name: asset_depreciations id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.asset_depreciations ALTER COLUMN id SET DEFAULT nextval('public.asset_depreciations_id_seq'::regclass);


--
-- Name: assets id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.assets ALTER COLUMN id SET DEFAULT nextval('public.assets_id_seq'::regclass);


--
-- Name: attendances id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.attendances ALTER COLUMN id SET DEFAULT nextval('public.attendances_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: bank_file_records id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bank_file_records ALTER COLUMN id SET DEFAULT nextval('public.bank_file_records_id_seq'::regclass);


--
-- Name: bill_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_items ALTER COLUMN id SET DEFAULT nextval('public.bill_items_id_seq'::regclass);


--
-- Name: bill_of_materials id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_of_materials ALTER COLUMN id SET DEFAULT nextval('public.bill_of_materials_id_seq'::regclass);


--
-- Name: bill_payments id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_payments ALTER COLUMN id SET DEFAULT nextval('public.bill_payments_id_seq'::regclass);


--
-- Name: bills id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills ALTER COLUMN id SET DEFAULT nextval('public.bills_id_seq'::regclass);


--
-- Name: bom_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bom_items ALTER COLUMN id SET DEFAULT nextval('public.bom_items_id_seq'::regclass);


--
-- Name: clearances id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances ALTER COLUMN id SET DEFAULT nextval('public.clearances_id_seq'::regclass);


--
-- Name: collections id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.collections ALTER COLUMN id SET DEFAULT nextval('public.collections_id_seq'::regclass);


--
-- Name: complaint_8d_reports id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.complaint_8d_reports ALTER COLUMN id SET DEFAULT nextval('public.complaint_8d_reports_id_seq'::regclass);


--
-- Name: customer_complaints id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints ALTER COLUMN id SET DEFAULT nextval('public.customer_complaints_id_seq'::regclass);


--
-- Name: customers id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customers ALTER COLUMN id SET DEFAULT nextval('public.customers_id_seq'::regclass);


--
-- Name: defect_types id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.defect_types ALTER COLUMN id SET DEFAULT nextval('public.defect_types_id_seq'::regclass);


--
-- Name: deliveries id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries ALTER COLUMN id SET DEFAULT nextval('public.deliveries_id_seq'::regclass);


--
-- Name: delivery_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.delivery_items ALTER COLUMN id SET DEFAULT nextval('public.delivery_items_id_seq'::regclass);


--
-- Name: departments id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.departments ALTER COLUMN id SET DEFAULT nextval('public.departments_id_seq'::regclass);


--
-- Name: document_sequences id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.document_sequences ALTER COLUMN id SET DEFAULT nextval('public.document_sequences_id_seq'::regclass);


--
-- Name: employee_documents id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_documents ALTER COLUMN id SET DEFAULT nextval('public.employee_documents_id_seq'::regclass);


--
-- Name: employee_leave_balances id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_leave_balances ALTER COLUMN id SET DEFAULT nextval('public.employee_leave_balances_id_seq'::regclass);


--
-- Name: employee_loans id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_loans ALTER COLUMN id SET DEFAULT nextval('public.employee_loans_id_seq'::regclass);


--
-- Name: employee_property id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_property ALTER COLUMN id SET DEFAULT nextval('public.employee_property_id_seq'::regclass);


--
-- Name: employee_shift_assignments id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_shift_assignments ALTER COLUMN id SET DEFAULT nextval('public.employee_shift_assignments_id_seq'::regclass);


--
-- Name: employees id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employees ALTER COLUMN id SET DEFAULT nextval('public.employees_id_seq'::regclass);


--
-- Name: employment_history id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employment_history ALTER COLUMN id SET DEFAULT nextval('public.employment_history_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: goods_receipt_notes id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes ALTER COLUMN id SET DEFAULT nextval('public.goods_receipt_notes_id_seq'::regclass);


--
-- Name: government_contribution_tables id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.government_contribution_tables ALTER COLUMN id SET DEFAULT nextval('public.government_contribution_tables_id_seq'::regclass);


--
-- Name: grn_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.grn_items ALTER COLUMN id SET DEFAULT nextval('public.grn_items_id_seq'::regclass);


--
-- Name: holidays id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.holidays ALTER COLUMN id SET DEFAULT nextval('public.holidays_id_seq'::regclass);


--
-- Name: inspection_measurements id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_measurements ALTER COLUMN id SET DEFAULT nextval('public.inspection_measurements_id_seq'::regclass);


--
-- Name: inspection_spec_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_spec_items ALTER COLUMN id SET DEFAULT nextval('public.inspection_spec_items_id_seq'::regclass);


--
-- Name: inspection_specs id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_specs ALTER COLUMN id SET DEFAULT nextval('public.inspection_specs_id_seq'::regclass);


--
-- Name: inspections id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspections ALTER COLUMN id SET DEFAULT nextval('public.inspections_id_seq'::regclass);


--
-- Name: invoice_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoice_items ALTER COLUMN id SET DEFAULT nextval('public.invoice_items_id_seq'::regclass);


--
-- Name: invoices id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoices ALTER COLUMN id SET DEFAULT nextval('public.invoices_id_seq'::regclass);


--
-- Name: item_categories id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.item_categories ALTER COLUMN id SET DEFAULT nextval('public.item_categories_id_seq'::regclass);


--
-- Name: items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.items ALTER COLUMN id SET DEFAULT nextval('public.items_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: journal_entries id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entries ALTER COLUMN id SET DEFAULT nextval('public.journal_entries_id_seq'::regclass);


--
-- Name: journal_entry_lines id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entry_lines ALTER COLUMN id SET DEFAULT nextval('public.journal_entry_lines_id_seq'::regclass);


--
-- Name: leave_requests id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests ALTER COLUMN id SET DEFAULT nextval('public.leave_requests_id_seq'::regclass);


--
-- Name: leave_types id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_types ALTER COLUMN id SET DEFAULT nextval('public.leave_types_id_seq'::regclass);


--
-- Name: loan_payments id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.loan_payments ALTER COLUMN id SET DEFAULT nextval('public.loan_payments_id_seq'::regclass);


--
-- Name: machine_downtimes id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machine_downtimes ALTER COLUMN id SET DEFAULT nextval('public.machine_downtimes_id_seq'::regclass);


--
-- Name: machines id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machines ALTER COLUMN id SET DEFAULT nextval('public.machines_id_seq'::regclass);


--
-- Name: maintenance_logs id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_logs ALTER COLUMN id SET DEFAULT nextval('public.maintenance_logs_id_seq'::regclass);


--
-- Name: maintenance_schedules id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_schedules ALTER COLUMN id SET DEFAULT nextval('public.maintenance_schedules_id_seq'::regclass);


--
-- Name: maintenance_work_orders id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_work_orders ALTER COLUMN id SET DEFAULT nextval('public.maintenance_work_orders_id_seq'::regclass);


--
-- Name: material_issue_slip_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slip_items ALTER COLUMN id SET DEFAULT nextval('public.material_issue_slip_items_id_seq'::regclass);


--
-- Name: material_issue_slips id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slips ALTER COLUMN id SET DEFAULT nextval('public.material_issue_slips_id_seq'::regclass);


--
-- Name: material_reservations id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_reservations ALTER COLUMN id SET DEFAULT nextval('public.material_reservations_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: mold_history id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mold_history ALTER COLUMN id SET DEFAULT nextval('public.mold_history_id_seq'::regclass);


--
-- Name: molds id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.molds ALTER COLUMN id SET DEFAULT nextval('public.molds_id_seq'::regclass);


--
-- Name: mrp_plans id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mrp_plans ALTER COLUMN id SET DEFAULT nextval('public.mrp_plans_id_seq'::regclass);


--
-- Name: ncr_actions id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.ncr_actions ALTER COLUMN id SET DEFAULT nextval('public.ncr_actions_id_seq'::regclass);


--
-- Name: non_conformance_reports id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports ALTER COLUMN id SET DEFAULT nextval('public.non_conformance_reports_id_seq'::regclass);


--
-- Name: notification_preferences id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.notification_preferences ALTER COLUMN id SET DEFAULT nextval('public.notification_preferences_id_seq'::regclass);


--
-- Name: overtime_requests id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.overtime_requests ALTER COLUMN id SET DEFAULT nextval('public.overtime_requests_id_seq'::regclass);


--
-- Name: password_history id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.password_history ALTER COLUMN id SET DEFAULT nextval('public.password_history_id_seq'::regclass);


--
-- Name: payroll_adjustments id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments ALTER COLUMN id SET DEFAULT nextval('public.payroll_adjustments_id_seq'::regclass);


--
-- Name: payroll_deduction_details id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_deduction_details ALTER COLUMN id SET DEFAULT nextval('public.payroll_deduction_details_id_seq'::regclass);


--
-- Name: payroll_periods id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_periods ALTER COLUMN id SET DEFAULT nextval('public.payroll_periods_id_seq'::regclass);


--
-- Name: payrolls id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payrolls ALTER COLUMN id SET DEFAULT nextval('public.payrolls_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: positions id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.positions ALTER COLUMN id SET DEFAULT nextval('public.positions_id_seq'::regclass);


--
-- Name: product_price_agreements id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.product_price_agreements ALTER COLUMN id SET DEFAULT nextval('public.product_price_agreements_id_seq'::regclass);


--
-- Name: production_schedules id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.production_schedules ALTER COLUMN id SET DEFAULT nextval('public.production_schedules_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: purchase_order_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_order_items ALTER COLUMN id SET DEFAULT nextval('public.purchase_order_items_id_seq'::regclass);


--
-- Name: purchase_orders id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders ALTER COLUMN id SET DEFAULT nextval('public.purchase_orders_id_seq'::regclass);


--
-- Name: purchase_request_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_request_items ALTER COLUMN id SET DEFAULT nextval('public.purchase_request_items_id_seq'::regclass);


--
-- Name: purchase_requests id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_requests ALTER COLUMN id SET DEFAULT nextval('public.purchase_requests_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: sales_order_items id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_order_items ALTER COLUMN id SET DEFAULT nextval('public.sales_order_items_id_seq'::regclass);


--
-- Name: sales_orders id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_orders ALTER COLUMN id SET DEFAULT nextval('public.sales_orders_id_seq'::regclass);


--
-- Name: settings id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.settings ALTER COLUMN id SET DEFAULT nextval('public.settings_id_seq'::regclass);


--
-- Name: shifts id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shifts ALTER COLUMN id SET DEFAULT nextval('public.shifts_id_seq'::regclass);


--
-- Name: shipment_documents id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipment_documents ALTER COLUMN id SET DEFAULT nextval('public.shipment_documents_id_seq'::regclass);


--
-- Name: shipments id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipments ALTER COLUMN id SET DEFAULT nextval('public.shipments_id_seq'::regclass);


--
-- Name: spare_part_usage id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.spare_part_usage ALTER COLUMN id SET DEFAULT nextval('public.spare_part_usage_id_seq'::regclass);


--
-- Name: stock_levels id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_levels ALTER COLUMN id SET DEFAULT nextval('public.stock_levels_id_seq'::regclass);


--
-- Name: stock_movements id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_movements ALTER COLUMN id SET DEFAULT nextval('public.stock_movements_id_seq'::regclass);


--
-- Name: thirteenth_month_accruals id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.thirteenth_month_accruals ALTER COLUMN id SET DEFAULT nextval('public.thirteenth_month_accruals_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vehicles id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.vehicles ALTER COLUMN id SET DEFAULT nextval('public.vehicles_id_seq'::regclass);


--
-- Name: vendors id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.vendors ALTER COLUMN id SET DEFAULT nextval('public.vendors_id_seq'::regclass);


--
-- Name: warehouse_locations id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_locations ALTER COLUMN id SET DEFAULT nextval('public.warehouse_locations_id_seq'::regclass);


--
-- Name: warehouse_zones id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_zones ALTER COLUMN id SET DEFAULT nextval('public.warehouse_zones_id_seq'::regclass);


--
-- Name: warehouses id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouses ALTER COLUMN id SET DEFAULT nextval('public.warehouses_id_seq'::regclass);


--
-- Name: work_order_defects id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_defects ALTER COLUMN id SET DEFAULT nextval('public.work_order_defects_id_seq'::regclass);


--
-- Name: work_order_materials id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_materials ALTER COLUMN id SET DEFAULT nextval('public.work_order_materials_id_seq'::regclass);


--
-- Name: work_order_outputs id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_outputs ALTER COLUMN id SET DEFAULT nextval('public.work_order_outputs_id_seq'::regclass);


--
-- Name: work_orders id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders ALTER COLUMN id SET DEFAULT nextval('public.work_orders_id_seq'::regclass);


--
-- Name: workflow_definitions id; Type: DEFAULT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.workflow_definitions ALTER COLUMN id SET DEFAULT nextval('public.workflow_definitions_id_seq'::regclass);


--
-- Data for Name: accounts; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.accounts (id, code, name, type, normal_balance, parent_id, is_active, description, created_at, updated_at) FROM stdin;
1	1000	Assets	asset	debit	\N	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
2	2000	Liabilities	liability	credit	\N	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
3	3000	Equity	equity	credit	\N	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
4	4000	Revenue	revenue	credit	\N	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
5	5000	Cost of Goods Sold	expense	debit	\N	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
6	6000	Operating Expenses	expense	debit	\N	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
8	1020	Cash in Bank	asset	debit	1	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:03
9	1030	Petty Cash	asset	debit	1	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:03
10	1100	Accounts Receivable	asset	debit	1	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:03
11	1200	Inventory - Raw Materials	asset	debit	1	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:03
12	1210	Inventory - Finished Goods	asset	debit	1	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:03
13	1220	Inventory - Packaging	asset	debit	1	t	\N	2026-05-03 17:07:02	2026-05-03 17:07:03
14	1230	Inventory - Spare Parts	asset	debit	1	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
15	1300	Prepaid Expenses	asset	debit	1	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
16	1310	VAT Input	asset	debit	1	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
17	1400	Property Plant & Equipment	asset	debit	1	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
18	1410	Accumulated Depreciation	asset	credit	1	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
19	2010	Accounts Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
24	2060	VAT Output	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
25	2070	Accrued Expenses	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
28	3010	Capital Stock	equity	credit	3	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
29	3020	Retained Earnings	equity	credit	3	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
30	4010	Sales Revenue	revenue	credit	4	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
31	4020	Other Income	revenue	credit	4	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
32	5010	Direct Materials	expense	debit	5	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
33	5020	Direct Labor	expense	debit	5	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
34	5030	Manufacturing Overhead	expense	debit	5	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
38	6010	Salaries & Wages Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
39	6015	Overtime Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
40	6020	Employee Benefits Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
44	6060	Utilities Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
45	6070	Rent Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
46	6080	Depreciation Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
47	6090	Office Supplies Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
48	6100	Repairs & Maintenance Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
49	6110	Transportation Expense	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
50	6120	Loss on Disposal of Asset	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
51	4030	Gain on Disposal of Asset	revenue	credit	4	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
7	1010	Cash in Bank	asset	debit	1	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
20	2020	SSS Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
21	2030	PhilHealth Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
22	2040	Pag-IBIG Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
23	2050	Withholding Tax Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
26	2080	13th Month Pay Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
27	2100	Loans Payable	liability	credit	2	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
35	5050	Salaries Expense	expense	debit	5	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
36	5060	Overtime Expense	expense	debit	5	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
41	6030	SSS Expense (Employer)	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
42	6040	PhilHealth Expense (Employer)	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
43	6050	Pag-IBIG Expense (Employer)	expense	debit	6	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
37	5070	13th Month Expense	expense	debit	5	t	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: approval_records; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.approval_records (id, approvable_type, approvable_id, step_order, role_slug, approver_id, action, remarks, acted_at, created_at) FROM stdin;
\.


--
-- Data for Name: approved_suppliers; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.approved_suppliers (id, item_id, vendor_id, is_preferred, lead_time_days, last_price, last_price_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: asset_depreciations; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.asset_depreciations (id, asset_id, period_year, period_month, depreciation_amount, accumulated_after, journal_entry_id, created_at) FROM stdin;
\.


--
-- Data for Name: assets; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.assets (id, asset_code, name, description, category, department_id, acquisition_date, acquisition_cost, useful_life_years, salvage_value, accumulated_depreciation, status, disposed_date, disposal_amount, location, created_at, updated_at, deleted_at) FROM stdin;
1	AST-MCH-0001	Toshiba EC100SX (IMM-01)	Injection molding machine	machine	\N	2023-05-03	6759624.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
2	AST-MCH-0002	Toshiba EC100SX (IMM-02)	Injection molding machine	machine	\N	2018-05-03	4100210.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
3	AST-MCH-0003	Sumitomo SE130 (IMM-03)	Injection molding machine	machine	\N	2020-05-03	2823969.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
4	AST-MCH-0004	Sumitomo SE130 (IMM-04)	Injection molding machine	machine	\N	2023-05-03	3944758.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
5	AST-MCH-0005	Nissei NEX180 (IMM-05)	Injection molding machine	machine	\N	2020-05-03	4187635.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
6	AST-MCH-0006	Nissei NEX180 (IMM-06)	Injection molding machine	machine	\N	2023-05-03	6209490.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
7	AST-MCH-0007	Fanuc Roboshot (IMM-07)	Injection molding machine	machine	\N	2021-05-03	7516023.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
8	AST-MCH-0008	Fanuc Roboshot (IMM-08)	Injection molding machine	machine	\N	2024-05-03	4135847.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
9	AST-MCH-0009	JSW J280AD (IMM-09)	Injection molding machine	machine	\N	2025-05-03	2151210.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
10	AST-MCH-0010	JSW J280AD (IMM-10)	Injection molding machine	machine	\N	2024-05-03	7001428.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
11	AST-MCH-0011	Toshiba EC450 (IMM-11)	Injection molding machine	machine	\N	2018-05-03	3738274.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
12	AST-MCH-0012	Toshiba EC650 (IMM-12)	Injection molding machine	machine	\N	2019-05-03	3847784.00	10	100000.00	0.00	active	\N	\N	Production Floor	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
13	AST-MLD-0001	WB-001 4-cav steel mold A (M-WB-001)	Injection mold	mold	\N	2022-05-03	902309.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
14	AST-MLD-0002	WB-001 4-cav steel mold B (M-WB-002)	Injection mold	mold	\N	2023-05-03	729412.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
15	AST-MLD-0003	WB-002 4-cav heavy duty (M-WB-003)	Injection mold	mold	\N	2023-05-03	1251603.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
16	AST-MLD-0004	PC-001 8-cav (M-PC-001)	Injection mold	mold	\N	2025-05-03	307872.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
17	AST-MLD-0005	PC-001 8-cav backup (M-PC-002)	Injection mold	mold	\N	2024-05-03	908039.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
18	AST-MLD-0006	PC-002 8-cav (M-PC-003)	Injection mold	mold	\N	2024-05-03	1133920.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
19	AST-MLD-0007	RC-001 2-cav (M-RC-001)	Injection mold	mold	\N	2022-05-03	1434043.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
20	AST-MLD-0008	RC-001 2-cav backup (M-RC-002)	Injection mold	mold	\N	2024-05-03	1059238.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
21	AST-MLD-0009	RC-002 2-cav large (M-RC-003)	Injection mold	mold	\N	2023-05-03	436581.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
22	AST-MLD-0010	BB-001 4-cav bobbin (M-BB-001)	Injection mold	mold	\N	2022-05-03	958119.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
23	AST-MLD-0011	BB-001 4-cav bobbin backup (M-BB-002)	Injection mold	mold	\N	2022-05-03	801553.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
24	AST-MLD-0012	BU-001 6-cav (M-BU-001)	Injection mold	mold	\N	2024-05-03	868573.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
25	AST-MLD-0013	BU-001 6-cav backup (M-BU-002)	Injection mold	mold	\N	2023-05-03	719742.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
26	AST-MLD-0014	BU-001 6-cav backup 2 (M-BU-003)	Injection mold	mold	\N	2025-05-03	1486367.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
27	AST-MLD-0015	WB-002 4-cav backup (M-WB-004)	Injection mold	mold	\N	2022-05-03	1346740.00	5	25000.00	0.00	active	\N	\N	Mold Storage Bay	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
28	AST-VEH-0001	Truck 1 (TRK-001)	Delivery vehicle	vehicle	\N	2020-05-03	1456594.00	7	50000.00	0.00	active	\N	\N	Vehicle Yard	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
29	AST-VEH-0002	Truck 2 (TRK-002)	Delivery vehicle	vehicle	\N	2024-05-03	1138353.00	7	50000.00	0.00	active	\N	\N	Vehicle Yard	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
30	AST-VEH-0003	L300 Van (VAN-001)	Delivery vehicle	vehicle	\N	2020-05-03	1229683.00	7	50000.00	0.00	active	\N	\N	Vehicle Yard	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
\.


--
-- Data for Name: attendances; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.attendances (id, employee_id, date, shift_id, time_in, time_out, regular_hours, overtime_hours, night_diff_hours, tardiness_minutes, undertime_minutes, holiday_type, is_rest_day, day_type_rate, status, is_manual_entry, remarks, created_at, updated_at) FROM stdin;
1	1	2026-04-27	\N	2026-04-27 08:00:00	2026-04-27 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
2	2	2026-04-27	\N	2026-04-27 08:00:00	2026-04-27 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
3	3	2026-04-27	\N	2026-04-27 08:00:00	2026-04-27 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
4	4	2026-04-27	\N	2026-04-27 08:00:00	2026-04-27 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
5	5	2026-04-27	\N	2026-04-27 08:00:00	2026-04-27 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
6	1	2026-04-28	\N	2026-04-28 08:00:00	2026-04-28 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
7	2	2026-04-28	\N	2026-04-28 08:00:00	2026-04-28 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
8	3	2026-04-28	\N	2026-04-28 08:00:00	2026-04-28 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
9	4	2026-04-28	\N	2026-04-28 08:00:00	2026-04-28 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
10	5	2026-04-28	\N	2026-04-28 08:00:00	2026-04-28 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
11	1	2026-04-29	\N	2026-04-29 08:00:00	2026-04-29 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
12	2	2026-04-29	\N	2026-04-29 08:00:00	2026-04-29 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
13	3	2026-04-29	\N	2026-04-29 08:00:00	2026-04-29 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
14	4	2026-04-29	\N	2026-04-29 08:00:00	2026-04-29 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
15	5	2026-04-29	\N	2026-04-29 08:00:00	2026-04-29 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
16	1	2026-04-30	\N	2026-04-30 08:00:00	2026-04-30 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
17	2	2026-04-30	\N	2026-04-30 08:00:00	2026-04-30 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
18	3	2026-04-30	\N	2026-04-30 08:00:00	2026-04-30 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
19	4	2026-04-30	\N	2026-04-30 08:00:00	2026-04-30 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
20	5	2026-04-30	\N	2026-04-30 08:00:00	2026-04-30 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
21	1	2026-05-01	\N	2026-05-01 08:00:00	2026-05-01 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
22	2	2026-05-01	\N	2026-05-01 08:00:00	2026-05-01 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
23	3	2026-05-01	\N	2026-05-01 08:00:00	2026-05-01 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
24	4	2026-05-01	\N	2026-05-01 08:00:00	2026-05-01 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
25	5	2026-05-01	\N	2026-05-01 08:00:00	2026-05-01 17:00:00	8.00	0.00	0.00	0	0	\N	f	1.00	present	t	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.audit_logs (id, user_id, action, model_type, model_id, old_values, new_values, ip_address, user_agent, created_at) FROM stdin;
1	\N	created	App\\Modules\\HR\\Models\\Department	1	\N	{"code":"EXEC","name":"Executive","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:02
2	\N	created	App\\Modules\\HR\\Models\\Department	2	\N	{"code":"HR","name":"Human Resources","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:02
3	\N	created	App\\Modules\\HR\\Models\\Department	3	\N	{"code":"FIN","name":"Finance & Accounting","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:02
4	\N	created	App\\Modules\\HR\\Models\\Department	4	\N	{"code":"PROD","name":"Production","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:02
5	\N	created	App\\Modules\\HR\\Models\\Department	5	\N	{"code":"QC","name":"Quality Control","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:02
6	\N	created	App\\Modules\\HR\\Models\\Department	6	\N	{"code":"WH","name":"Warehouse & Logistics","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:02
7	\N	created	App\\Modules\\HR\\Models\\Department	7	\N	{"code":"PUR","name":"Purchasing & Procurement","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:02
8	\N	created	App\\Modules\\HR\\Models\\Department	8	\N	{"code":"PPC","name":"Production Planning","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:02
9	\N	created	App\\Modules\\HR\\Models\\Department	9	\N	{"code":"MAINT","name":"Maintenance & Engineering","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:02
10	\N	created	App\\Modules\\HR\\Models\\Department	10	\N	{"code":"MOLD","name":"Mold Department","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:02
11	\N	created	App\\Modules\\HR\\Models\\Department	11	\N	{"code":"IMPEX","name":"Import\\/Export","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:02
12	\N	created	App\\Modules\\HR\\Models\\Department	12	\N	{"code":"ADMIN","name":"Admin & General Affairs","is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:02
13	\N	created	App\\Modules\\HR\\Models\\Position	1	\N	{"title":"Chairman","department_id":1,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:02
14	\N	created	App\\Modules\\HR\\Models\\Position	2	\N	{"title":"President","department_id":1,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:02
15	\N	created	App\\Modules\\HR\\Models\\Position	3	\N	{"title":"Vice President","department_id":1,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:02
16	\N	created	App\\Modules\\HR\\Models\\Position	4	\N	{"title":"HR Manager","department_id":2,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:02
17	\N	created	App\\Modules\\HR\\Models\\Position	5	\N	{"title":"Gen Admin Officer","department_id":2,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:02
18	\N	created	App\\Modules\\HR\\Models\\Position	6	\N	{"title":"HR Staff","department_id":2,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:02
19	\N	created	App\\Modules\\HR\\Models\\Position	7	\N	{"title":"Accounting Officer","department_id":3,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:02
20	\N	created	App\\Modules\\HR\\Models\\Position	8	\N	{"title":"Accounting Staff","department_id":3,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:02
21	\N	created	App\\Modules\\HR\\Models\\Position	9	\N	{"title":"Plant Manager","department_id":4,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:02
22	\N	created	App\\Modules\\HR\\Models\\Position	10	\N	{"title":"Production Manager","department_id":4,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:02
23	\N	created	App\\Modules\\HR\\Models\\Position	11	\N	{"title":"Production Head","department_id":4,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:02
24	\N	created	App\\Modules\\HR\\Models\\Position	12	\N	{"title":"Processing Head","department_id":4,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:02
25	\N	created	App\\Modules\\HR\\Models\\Position	13	\N	{"title":"Production Operator","department_id":4,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:02
26	\N	created	App\\Modules\\HR\\Models\\Position	14	\N	{"title":"QC\\/QA Manager","department_id":5,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:02
27	\N	created	App\\Modules\\HR\\Models\\Position	15	\N	{"title":"QC\\/QA Head","department_id":5,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:02
28	\N	created	App\\Modules\\HR\\Models\\Position	16	\N	{"title":"QC Inspector","department_id":5,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":16}	127.0.0.1	Symfony	2026-05-03 17:07:02
29	\N	created	App\\Modules\\HR\\Models\\Position	17	\N	{"title":"Management System Head","department_id":5,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":17}	127.0.0.1	Symfony	2026-05-03 17:07:02
30	\N	created	App\\Modules\\HR\\Models\\Position	18	\N	{"title":"Warehouse Head","department_id":6,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":18}	127.0.0.1	Symfony	2026-05-03 17:07:02
31	\N	created	App\\Modules\\HR\\Models\\Position	19	\N	{"title":"Warehouse Staff","department_id":6,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":19}	127.0.0.1	Symfony	2026-05-03 17:07:02
32	\N	created	App\\Modules\\HR\\Models\\Position	20	\N	{"title":"Driver","department_id":6,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":20}	127.0.0.1	Symfony	2026-05-03 17:07:02
33	\N	created	App\\Modules\\HR\\Models\\Position	21	\N	{"title":"Purchasing Officer","department_id":7,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":21}	127.0.0.1	Symfony	2026-05-03 17:07:02
34	\N	created	App\\Modules\\HR\\Models\\Position	22	\N	{"title":"Purchasing Staff","department_id":7,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":22}	127.0.0.1	Symfony	2026-05-03 17:07:02
35	\N	created	App\\Modules\\HR\\Models\\Position	23	\N	{"title":"PPC Head","department_id":8,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":23}	127.0.0.1	Symfony	2026-05-03 17:07:02
36	\N	created	App\\Modules\\HR\\Models\\Position	24	\N	{"title":"PPC Staff","department_id":8,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":24}	127.0.0.1	Symfony	2026-05-03 17:07:02
37	\N	created	App\\Modules\\HR\\Models\\Position	25	\N	{"title":"Maintenance Head","department_id":9,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":25}	127.0.0.1	Symfony	2026-05-03 17:07:02
38	\N	created	App\\Modules\\HR\\Models\\Position	26	\N	{"title":"Maintenance Technician","department_id":9,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":26}	127.0.0.1	Symfony	2026-05-03 17:07:02
39	\N	created	App\\Modules\\HR\\Models\\Position	27	\N	{"title":"Mold Manager","department_id":10,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":27}	127.0.0.1	Symfony	2026-05-03 17:07:02
40	\N	created	App\\Modules\\HR\\Models\\Position	28	\N	{"title":"Mold Technician","department_id":10,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":28}	127.0.0.1	Symfony	2026-05-03 17:07:02
41	\N	created	App\\Modules\\HR\\Models\\Position	29	\N	{"title":"ImpEx Officer","department_id":11,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":29}	127.0.0.1	Symfony	2026-05-03 17:07:02
42	\N	created	App\\Modules\\HR\\Models\\Position	30	\N	{"title":"ImpEx Staff","department_id":11,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":30}	127.0.0.1	Symfony	2026-05-03 17:07:02
43	\N	created	App\\Modules\\HR\\Models\\Position	31	\N	{"title":"Admin Staff","department_id":12,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":31}	127.0.0.1	Symfony	2026-05-03 17:07:02
44	\N	created	App\\Modules\\Attendance\\Models\\Shift	1	\N	{"name":"Day Shift","start_time":"06:00","end_time":"14:00","break_minutes":30,"is_night_shift":false,"is_extended":false,"auto_ot_hours":null,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:02
45	\N	created	App\\Modules\\Attendance\\Models\\Shift	2	\N	{"name":"Extended Day","start_time":"06:00","end_time":"18:00","break_minutes":30,"is_night_shift":false,"is_extended":true,"auto_ot_hours":4,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:02
46	\N	created	App\\Modules\\Attendance\\Models\\Shift	3	\N	{"name":"Night Shift","start_time":"18:00","end_time":"06:00","break_minutes":30,"is_night_shift":true,"is_extended":false,"auto_ot_hours":null,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:02
47	\N	created	App\\Modules\\Attendance\\Models\\Shift	4	\N	{"name":"Office Hours","start_time":"08:00","end_time":"17:00","break_minutes":60,"is_night_shift":false,"is_extended":false,"auto_ot_hours":null,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:02
48	\N	created	App\\Modules\\Attendance\\Models\\Holiday	1	\N	{"date":"2026-01-01 00:00:00","name":"New Year's Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:02
49	\N	created	App\\Modules\\Attendance\\Models\\Holiday	2	\N	{"date":"2026-02-17 00:00:00","name":"Chinese New Year","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:02
50	\N	created	App\\Modules\\Attendance\\Models\\Holiday	3	\N	{"date":"2026-02-25 00:00:00","name":"EDSA Revolution Anniversary","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:02
51	\N	created	App\\Modules\\Attendance\\Models\\Holiday	4	\N	{"date":"2026-03-20 00:00:00","name":"Eid'l Fitr","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:02
52	\N	created	App\\Modules\\Attendance\\Models\\Holiday	5	\N	{"date":"2026-04-02 00:00:00","name":"Maundy Thursday","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:02
53	\N	created	App\\Modules\\Attendance\\Models\\Holiday	6	\N	{"date":"2026-04-03 00:00:00","name":"Good Friday","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:02
54	\N	created	App\\Modules\\Attendance\\Models\\Holiday	7	\N	{"date":"2026-04-04 00:00:00","name":"Black Saturday","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:02
55	\N	created	App\\Modules\\Attendance\\Models\\Holiday	8	\N	{"date":"2026-04-09 00:00:00","name":"Araw ng Kagitingan","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:02
56	\N	created	App\\Modules\\Attendance\\Models\\Holiday	9	\N	{"date":"2026-05-01 00:00:00","name":"Labor Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:02
57	\N	created	App\\Modules\\Attendance\\Models\\Holiday	10	\N	{"date":"2026-05-27 00:00:00","name":"Eid'l Adha","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:02
58	\N	created	App\\Modules\\Attendance\\Models\\Holiday	11	\N	{"date":"2026-06-12 00:00:00","name":"Independence Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:02
59	\N	created	App\\Modules\\Attendance\\Models\\Holiday	12	\N	{"date":"2026-08-21 00:00:00","name":"Ninoy Aquino Day","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:02
60	\N	created	App\\Modules\\Attendance\\Models\\Holiday	13	\N	{"date":"2026-08-31 00:00:00","name":"National Heroes Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:02
61	\N	created	App\\Modules\\Attendance\\Models\\Holiday	14	\N	{"date":"2026-11-01 00:00:00","name":"All Saints' Day","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:02
62	\N	created	App\\Modules\\Attendance\\Models\\Holiday	15	\N	{"date":"2026-11-02 00:00:00","name":"All Souls' Day","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:02
63	\N	created	App\\Modules\\Attendance\\Models\\Holiday	16	\N	{"date":"2026-11-30 00:00:00","name":"Bonifacio Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":16}	127.0.0.1	Symfony	2026-05-03 17:07:02
64	\N	created	App\\Modules\\Attendance\\Models\\Holiday	17	\N	{"date":"2026-12-08 00:00:00","name":"Feast of Immaculate Conception","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":17}	127.0.0.1	Symfony	2026-05-03 17:07:02
65	\N	created	App\\Modules\\Attendance\\Models\\Holiday	18	\N	{"date":"2026-12-24 00:00:00","name":"Christmas Eve","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":18}	127.0.0.1	Symfony	2026-05-03 17:07:02
66	\N	created	App\\Modules\\Attendance\\Models\\Holiday	19	\N	{"date":"2026-12-25 00:00:00","name":"Christmas Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":19}	127.0.0.1	Symfony	2026-05-03 17:07:02
67	\N	created	App\\Modules\\Attendance\\Models\\Holiday	20	\N	{"date":"2026-12-30 00:00:00","name":"Rizal Day","type":"regular","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":20}	127.0.0.1	Symfony	2026-05-03 17:07:02
68	\N	created	App\\Modules\\Attendance\\Models\\Holiday	21	\N	{"date":"2026-12-31 00:00:00","name":"Last Day of the Year","type":"special_non_working","is_recurring":false,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":21}	127.0.0.1	Symfony	2026-05-03 17:07:02
69	\N	created	App\\Modules\\Leave\\Models\\LeaveType	1	\N	{"code":"VL","name":"Vacation Leave","default_balance":15,"is_paid":true,"requires_document":false,"is_convertible_on_separation":true,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:02
70	\N	created	App\\Modules\\Leave\\Models\\LeaveType	2	\N	{"code":"SL","name":"Sick Leave","default_balance":15,"is_paid":true,"requires_document":true,"is_convertible_on_separation":false,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:02
71	\N	created	App\\Modules\\Leave\\Models\\LeaveType	3	\N	{"code":"SIL","name":"Service Incentive Leave","default_balance":5,"is_paid":true,"requires_document":false,"is_convertible_on_separation":true,"is_convertible_year_end":true,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:02
72	\N	created	App\\Modules\\Leave\\Models\\LeaveType	4	\N	{"code":"ML","name":"Maternity Leave","default_balance":105,"is_paid":true,"requires_document":true,"is_convertible_on_separation":false,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:02
73	\N	created	App\\Modules\\Leave\\Models\\LeaveType	5	\N	{"code":"PL","name":"Paternity Leave","default_balance":7,"is_paid":true,"requires_document":true,"is_convertible_on_separation":false,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:02
74	\N	created	App\\Modules\\Leave\\Models\\LeaveType	6	\N	{"code":"SPL","name":"Solo Parent Leave","default_balance":7,"is_paid":true,"requires_document":true,"is_convertible_on_separation":false,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:02
75	\N	created	App\\Modules\\Leave\\Models\\LeaveType	7	\N	{"code":"VAWC","name":"VAWC Leave","default_balance":10,"is_paid":true,"requires_document":true,"is_convertible_on_separation":false,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:02
76	\N	created	App\\Modules\\Leave\\Models\\LeaveType	8	\N	{"code":"SLW","name":"Special Leave for Women","default_balance":60,"is_paid":true,"requires_document":true,"is_convertible_on_separation":false,"is_convertible_year_end":false,"conversion_rate":1,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:02
77	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	1	\N	{"agency":"sss","bracket_min":0,"effective_date":"2024-01-01 00:00:00","bracket_max":4249.99,"ee_amount":180,"er_amount":390,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:02
78	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	2	\N	{"agency":"sss","bracket_min":4250,"effective_date":"2024-01-01 00:00:00","bracket_max":4749.99,"ee_amount":202.5,"er_amount":437.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:02
79	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	3	\N	{"agency":"sss","bracket_min":4750,"effective_date":"2024-01-01 00:00:00","bracket_max":5249.99,"ee_amount":225,"er_amount":485,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:02
80	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	4	\N	{"agency":"sss","bracket_min":5250,"effective_date":"2024-01-01 00:00:00","bracket_max":5749.99,"ee_amount":247.5,"er_amount":532.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:02
81	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	5	\N	{"agency":"sss","bracket_min":5750,"effective_date":"2024-01-01 00:00:00","bracket_max":6249.99,"ee_amount":270,"er_amount":580,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:02
82	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	6	\N	{"agency":"sss","bracket_min":6250,"effective_date":"2024-01-01 00:00:00","bracket_max":6749.99,"ee_amount":292.5,"er_amount":627.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:02
83	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	7	\N	{"agency":"sss","bracket_min":6750,"effective_date":"2024-01-01 00:00:00","bracket_max":7249.99,"ee_amount":315,"er_amount":675,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:02
84	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	8	\N	{"agency":"sss","bracket_min":7250,"effective_date":"2024-01-01 00:00:00","bracket_max":7749.99,"ee_amount":337.5,"er_amount":722.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:02
85	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	9	\N	{"agency":"sss","bracket_min":7750,"effective_date":"2024-01-01 00:00:00","bracket_max":8249.99,"ee_amount":360,"er_amount":770,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:02
86	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	10	\N	{"agency":"sss","bracket_min":8250,"effective_date":"2024-01-01 00:00:00","bracket_max":8749.99,"ee_amount":382.5,"er_amount":817.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:02
87	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	11	\N	{"agency":"sss","bracket_min":8750,"effective_date":"2024-01-01 00:00:00","bracket_max":9249.99,"ee_amount":405,"er_amount":865,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:02
88	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	12	\N	{"agency":"sss","bracket_min":9250,"effective_date":"2024-01-01 00:00:00","bracket_max":9749.99,"ee_amount":427.5,"er_amount":912.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:02
89	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	13	\N	{"agency":"sss","bracket_min":9750,"effective_date":"2024-01-01 00:00:00","bracket_max":10249.99,"ee_amount":450,"er_amount":960,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:02
90	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	14	\N	{"agency":"sss","bracket_min":10250,"effective_date":"2024-01-01 00:00:00","bracket_max":10749.99,"ee_amount":472.5,"er_amount":1007.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:02
91	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	15	\N	{"agency":"sss","bracket_min":10750,"effective_date":"2024-01-01 00:00:00","bracket_max":11249.99,"ee_amount":495,"er_amount":1055,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:02
92	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	16	\N	{"agency":"sss","bracket_min":11250,"effective_date":"2024-01-01 00:00:00","bracket_max":11749.99,"ee_amount":517.5,"er_amount":1102.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":16}	127.0.0.1	Symfony	2026-05-03 17:07:02
93	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	17	\N	{"agency":"sss","bracket_min":11750,"effective_date":"2024-01-01 00:00:00","bracket_max":12249.99,"ee_amount":540,"er_amount":1150,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":17}	127.0.0.1	Symfony	2026-05-03 17:07:02
94	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	18	\N	{"agency":"sss","bracket_min":12250,"effective_date":"2024-01-01 00:00:00","bracket_max":12749.99,"ee_amount":562.5,"er_amount":1197.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":18}	127.0.0.1	Symfony	2026-05-03 17:07:02
95	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	19	\N	{"agency":"sss","bracket_min":12750,"effective_date":"2024-01-01 00:00:00","bracket_max":13249.99,"ee_amount":585,"er_amount":1245,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":19}	127.0.0.1	Symfony	2026-05-03 17:07:02
96	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	20	\N	{"agency":"sss","bracket_min":13250,"effective_date":"2024-01-01 00:00:00","bracket_max":13749.99,"ee_amount":607.5,"er_amount":1292.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":20}	127.0.0.1	Symfony	2026-05-03 17:07:02
97	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	21	\N	{"agency":"sss","bracket_min":13750,"effective_date":"2024-01-01 00:00:00","bracket_max":14249.99,"ee_amount":630,"er_amount":1340,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":21}	127.0.0.1	Symfony	2026-05-03 17:07:02
98	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	22	\N	{"agency":"sss","bracket_min":14250,"effective_date":"2024-01-01 00:00:00","bracket_max":14749.99,"ee_amount":652.5,"er_amount":1387.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":22}	127.0.0.1	Symfony	2026-05-03 17:07:02
99	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	23	\N	{"agency":"sss","bracket_min":14750,"effective_date":"2024-01-01 00:00:00","bracket_max":15249.99,"ee_amount":675,"er_amount":1435,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":23}	127.0.0.1	Symfony	2026-05-03 17:07:02
100	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	24	\N	{"agency":"sss","bracket_min":15250,"effective_date":"2024-01-01 00:00:00","bracket_max":15749.99,"ee_amount":697.5,"er_amount":1482.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":24}	127.0.0.1	Symfony	2026-05-03 17:07:02
101	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	25	\N	{"agency":"sss","bracket_min":15750,"effective_date":"2024-01-01 00:00:00","bracket_max":16249.99,"ee_amount":720,"er_amount":1530,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":25}	127.0.0.1	Symfony	2026-05-03 17:07:02
102	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	26	\N	{"agency":"sss","bracket_min":16250,"effective_date":"2024-01-01 00:00:00","bracket_max":16749.99,"ee_amount":742.5,"er_amount":1577.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":26}	127.0.0.1	Symfony	2026-05-03 17:07:02
103	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	27	\N	{"agency":"sss","bracket_min":16750,"effective_date":"2024-01-01 00:00:00","bracket_max":17249.99,"ee_amount":765,"er_amount":1625,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":27}	127.0.0.1	Symfony	2026-05-03 17:07:02
168	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	9	\N	{"code":"A-09","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:03
104	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	28	\N	{"agency":"sss","bracket_min":17250,"effective_date":"2024-01-01 00:00:00","bracket_max":17749.99,"ee_amount":787.5,"er_amount":1672.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":28}	127.0.0.1	Symfony	2026-05-03 17:07:02
105	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	29	\N	{"agency":"sss","bracket_min":17750,"effective_date":"2024-01-01 00:00:00","bracket_max":18249.99,"ee_amount":810,"er_amount":1720,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":29}	127.0.0.1	Symfony	2026-05-03 17:07:02
106	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	30	\N	{"agency":"sss","bracket_min":18250,"effective_date":"2024-01-01 00:00:00","bracket_max":18749.99,"ee_amount":832.5,"er_amount":1767.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":30}	127.0.0.1	Symfony	2026-05-03 17:07:02
107	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	31	\N	{"agency":"sss","bracket_min":18750,"effective_date":"2024-01-01 00:00:00","bracket_max":19249.99,"ee_amount":855,"er_amount":1815,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":31}	127.0.0.1	Symfony	2026-05-03 17:07:02
108	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	32	\N	{"agency":"sss","bracket_min":19250,"effective_date":"2024-01-01 00:00:00","bracket_max":19749.99,"ee_amount":877.5,"er_amount":1862.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":32}	127.0.0.1	Symfony	2026-05-03 17:07:02
109	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	33	\N	{"agency":"sss","bracket_min":19750,"effective_date":"2024-01-01 00:00:00","bracket_max":20249.99,"ee_amount":900,"er_amount":1910,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":33}	127.0.0.1	Symfony	2026-05-03 17:07:02
110	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	34	\N	{"agency":"sss","bracket_min":20250,"effective_date":"2024-01-01 00:00:00","bracket_max":20749.99,"ee_amount":922.5,"er_amount":1957.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":34}	127.0.0.1	Symfony	2026-05-03 17:07:02
111	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	35	\N	{"agency":"sss","bracket_min":20750,"effective_date":"2024-01-01 00:00:00","bracket_max":21249.99,"ee_amount":945,"er_amount":2005,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":35}	127.0.0.1	Symfony	2026-05-03 17:07:02
112	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	36	\N	{"agency":"sss","bracket_min":21250,"effective_date":"2024-01-01 00:00:00","bracket_max":21749.99,"ee_amount":967.5,"er_amount":2052.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":36}	127.0.0.1	Symfony	2026-05-03 17:07:02
113	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	37	\N	{"agency":"sss","bracket_min":21750,"effective_date":"2024-01-01 00:00:00","bracket_max":22249.99,"ee_amount":990,"er_amount":2100,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":37}	127.0.0.1	Symfony	2026-05-03 17:07:02
114	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	38	\N	{"agency":"sss","bracket_min":22250,"effective_date":"2024-01-01 00:00:00","bracket_max":22749.99,"ee_amount":1012.5,"er_amount":2147.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":38}	127.0.0.1	Symfony	2026-05-03 17:07:02
115	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	39	\N	{"agency":"sss","bracket_min":22750,"effective_date":"2024-01-01 00:00:00","bracket_max":23249.99,"ee_amount":1035,"er_amount":2195,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":39}	127.0.0.1	Symfony	2026-05-03 17:07:02
116	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	40	\N	{"agency":"sss","bracket_min":23250,"effective_date":"2024-01-01 00:00:00","bracket_max":23749.99,"ee_amount":1057.5,"er_amount":2242.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":40}	127.0.0.1	Symfony	2026-05-03 17:07:02
117	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	41	\N	{"agency":"sss","bracket_min":23750,"effective_date":"2024-01-01 00:00:00","bracket_max":24249.99,"ee_amount":1080,"er_amount":2290,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":41}	127.0.0.1	Symfony	2026-05-03 17:07:02
118	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	42	\N	{"agency":"sss","bracket_min":24250,"effective_date":"2024-01-01 00:00:00","bracket_max":24749.99,"ee_amount":1102.5,"er_amount":2337.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":42}	127.0.0.1	Symfony	2026-05-03 17:07:02
119	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	43	\N	{"agency":"sss","bracket_min":24750,"effective_date":"2024-01-01 00:00:00","bracket_max":25249.99,"ee_amount":1125,"er_amount":2385,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":43}	127.0.0.1	Symfony	2026-05-03 17:07:02
120	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	44	\N	{"agency":"sss","bracket_min":25250,"effective_date":"2024-01-01 00:00:00","bracket_max":25749.99,"ee_amount":1147.5,"er_amount":2432.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":44}	127.0.0.1	Symfony	2026-05-03 17:07:02
121	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	45	\N	{"agency":"sss","bracket_min":25750,"effective_date":"2024-01-01 00:00:00","bracket_max":26249.99,"ee_amount":1170,"er_amount":2480,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":45}	127.0.0.1	Symfony	2026-05-03 17:07:02
122	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	46	\N	{"agency":"sss","bracket_min":26250,"effective_date":"2024-01-01 00:00:00","bracket_max":26749.99,"ee_amount":1192.5,"er_amount":2527.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":46}	127.0.0.1	Symfony	2026-05-03 17:07:02
123	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	47	\N	{"agency":"sss","bracket_min":26750,"effective_date":"2024-01-01 00:00:00","bracket_max":27249.99,"ee_amount":1215,"er_amount":2575,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":47}	127.0.0.1	Symfony	2026-05-03 17:07:02
124	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	48	\N	{"agency":"sss","bracket_min":27250,"effective_date":"2024-01-01 00:00:00","bracket_max":27749.99,"ee_amount":1237.5,"er_amount":2622.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":48}	127.0.0.1	Symfony	2026-05-03 17:07:02
125	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	49	\N	{"agency":"sss","bracket_min":27750,"effective_date":"2024-01-01 00:00:00","bracket_max":28249.99,"ee_amount":1260,"er_amount":2670,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":49}	127.0.0.1	Symfony	2026-05-03 17:07:02
126	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	50	\N	{"agency":"sss","bracket_min":28250,"effective_date":"2024-01-01 00:00:00","bracket_max":28749.99,"ee_amount":1282.5,"er_amount":2717.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":50}	127.0.0.1	Symfony	2026-05-03 17:07:02
127	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	51	\N	{"agency":"sss","bracket_min":28750,"effective_date":"2024-01-01 00:00:00","bracket_max":29249.99,"ee_amount":1305,"er_amount":2765,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":51}	127.0.0.1	Symfony	2026-05-03 17:07:02
128	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	52	\N	{"agency":"sss","bracket_min":29250,"effective_date":"2024-01-01 00:00:00","bracket_max":29749.99,"ee_amount":1327.5,"er_amount":2812.5,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":52}	127.0.0.1	Symfony	2026-05-03 17:07:02
129	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	53	\N	{"agency":"sss","bracket_min":29750,"effective_date":"2024-01-01 00:00:00","bracket_max":999999.99,"ee_amount":1350,"er_amount":2910,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":53}	127.0.0.1	Symfony	2026-05-03 17:07:02
130	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	54	\N	{"agency":"philhealth","bracket_min":10000,"effective_date":"2024-01-01 00:00:00","bracket_max":100000,"ee_amount":0.0225,"er_amount":0.0225,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":54}	127.0.0.1	Symfony	2026-05-03 17:07:02
131	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	55	\N	{"agency":"pagibig","bracket_min":0,"effective_date":"2024-01-01 00:00:00","bracket_max":1500,"ee_amount":0.01,"er_amount":0.02,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":55}	127.0.0.1	Symfony	2026-05-03 17:07:02
132	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	56	\N	{"agency":"pagibig","bracket_min":1500.01,"effective_date":"2024-01-01 00:00:00","bracket_max":999999.99,"ee_amount":0.02,"er_amount":0.02,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":56}	127.0.0.1	Symfony	2026-05-03 17:07:02
133	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	57	\N	{"agency":"bir","bracket_min":0,"effective_date":"2018-01-01 00:00:00","bracket_max":10416,"ee_amount":0,"er_amount":0,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":57}	127.0.0.1	Symfony	2026-05-03 17:07:02
134	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	58	\N	{"agency":"bir","bracket_min":10416.01,"effective_date":"2018-01-01 00:00:00","bracket_max":16666,"ee_amount":0,"er_amount":0.15,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":58}	127.0.0.1	Symfony	2026-05-03 17:07:02
135	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	59	\N	{"agency":"bir","bracket_min":16666.01,"effective_date":"2018-01-01 00:00:00","bracket_max":33332,"ee_amount":937.5,"er_amount":0.2,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":59}	127.0.0.1	Symfony	2026-05-03 17:07:02
136	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	60	\N	{"agency":"bir","bracket_min":33332.01,"effective_date":"2018-01-01 00:00:00","bracket_max":83332,"ee_amount":4270.83,"er_amount":0.25,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":60}	127.0.0.1	Symfony	2026-05-03 17:07:02
137	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	61	\N	{"agency":"bir","bracket_min":83332.01,"effective_date":"2018-01-01 00:00:00","bracket_max":333332,"ee_amount":16770.83,"er_amount":0.3,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":61}	127.0.0.1	Symfony	2026-05-03 17:07:02
138	\N	created	App\\Modules\\Payroll\\Models\\GovernmentContributionTable	62	\N	{"agency":"bir","bracket_min":333332.01,"effective_date":"2018-01-01 00:00:00","bracket_max":999999.99,"ee_amount":91770.83,"er_amount":0.35,"is_active":true,"updated_at":"2026-05-03 17:07:02","created_at":"2026-05-03 17:07:02","id":62}	127.0.0.1	Symfony	2026-05-03 17:07:02
139	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	1	\N	{"name":"Raw Materials","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
140	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	2	\N	{"name":"Packaging","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
141	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	3	\N	{"name":"Finished Goods","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
142	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	4	\N	{"name":"Spare Parts","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
143	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	5	\N	{"name":"Resins","parent_id":1,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
144	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	6	\N	{"name":"Colorants","parent_id":1,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
145	\N	created	App\\Modules\\Inventory\\Models\\ItemCategory	7	\N	{"name":"Metal Inserts","parent_id":1,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
146	\N	created	App\\Modules\\Inventory\\Models\\Item	1	\N	{"code":"RM-001","name":"Plastic Resin Type A (ABS)","category_id":5,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"120.0000","reorder_method":"fixed_quantity","reorder_point":"500.000","safety_stock":"200.000","minimum_order_quantity":"25.000","lead_time_days":14,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
147	\N	created	App\\Modules\\Inventory\\Models\\Item	2	\N	{"code":"RM-002","name":"Plastic Resin Type B (PP)","category_id":5,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"95.0000","reorder_method":"fixed_quantity","reorder_point":"500.000","safety_stock":"200.000","minimum_order_quantity":"25.000","lead_time_days":14,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
148	\N	created	App\\Modules\\Inventory\\Models\\Item	3	\N	{"code":"RM-003","name":"Plastic Resin Type C (PA)","category_id":5,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"150.0000","reorder_method":"fixed_quantity","reorder_point":"300.000","safety_stock":"120.000","minimum_order_quantity":"25.000","lead_time_days":14,"is_critical":true,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
149	\N	created	App\\Modules\\Inventory\\Models\\Item	4	\N	{"code":"RM-004","name":"Plastic Resin Type D (POM)","category_id":5,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"180.0000","reorder_method":"fixed_quantity","reorder_point":"300.000","safety_stock":"100.000","minimum_order_quantity":"25.000","lead_time_days":14,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
150	\N	created	App\\Modules\\Inventory\\Models\\Item	5	\N	{"code":"RM-010","name":"Black Colorant","category_id":6,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"250.0000","reorder_method":"fixed_quantity","reorder_point":"50.000","safety_stock":"20.000","minimum_order_quantity":"5.000","lead_time_days":7,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
151	\N	created	App\\Modules\\Inventory\\Models\\Item	6	\N	{"code":"RM-011","name":"White Colorant","category_id":6,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"280.0000","reorder_method":"fixed_quantity","reorder_point":"50.000","safety_stock":"20.000","minimum_order_quantity":"5.000","lead_time_days":7,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
152	\N	created	App\\Modules\\Inventory\\Models\\Item	7	\N	{"code":"RM-012","name":"Gray Colorant","category_id":6,"item_type":"raw_material","unit_of_measure":"kg","standard_cost":"260.0000","reorder_method":"fixed_quantity","reorder_point":"40.000","safety_stock":"15.000","minimum_order_quantity":"5.000","lead_time_days":7,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
153	\N	created	App\\Modules\\Inventory\\Models\\Item	8	\N	{"code":"RM-050","name":"Small Metal Insert","category_id":7,"item_type":"raw_material","unit_of_measure":"pcs","standard_cost":"5.5000","reorder_method":"fixed_quantity","reorder_point":"5000.000","safety_stock":"2000.000","minimum_order_quantity":"1000.000","lead_time_days":21,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:03
154	\N	created	App\\Modules\\Inventory\\Models\\Item	9	\N	{"code":"RM-051","name":"Large Metal Insert","category_id":7,"item_type":"raw_material","unit_of_measure":"pcs","standard_cost":"8.0000","reorder_method":"fixed_quantity","reorder_point":"4000.000","safety_stock":"1500.000","minimum_order_quantity":"1000.000","lead_time_days":21,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:03
155	\N	created	App\\Modules\\Inventory\\Models\\Item	10	\N	{"code":"RM-052","name":"Metal Core (Bobbin)","category_id":7,"item_type":"raw_material","unit_of_measure":"pcs","standard_cost":"12.0000","reorder_method":"fixed_quantity","reorder_point":"3000.000","safety_stock":"1000.000","minimum_order_quantity":"500.000","lead_time_days":21,"is_critical":true,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:03
156	\N	created	App\\Modules\\Inventory\\Models\\Item	11	\N	{"code":"PKG-001","name":"Standard Poly Bag","category_id":2,"item_type":"packaging","unit_of_measure":"pcs","standard_cost":"0.5000","reorder_method":"fixed_quantity","reorder_point":"2000.000","safety_stock":"500.000","minimum_order_quantity":"500.000","lead_time_days":5,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:03
157	\N	created	App\\Modules\\Inventory\\Models\\Item	12	\N	{"code":"PKG-002","name":"Shipping Box (50 pcs)","category_id":2,"item_type":"packaging","unit_of_measure":"pcs","standard_cost":"15.0000","reorder_method":"fixed_quantity","reorder_point":"1000.000","safety_stock":"300.000","minimum_order_quantity":"100.000","lead_time_days":5,"is_critical":false,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:03
158	\N	created	App\\Modules\\Inventory\\Models\\Warehouse	1	\N	{"code":"MW","name":"Main Warehouse","address":"FCIE Special Economic Zone, Dasmari\\u00f1as, Cavite","is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
159	\N	created	App\\Modules\\Inventory\\Models\\WarehouseZone	1	\N	{"warehouse_id":1,"code":"A","name":"Zone A \\u2014 Raw Materials","zone_type":"raw_materials","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
160	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	1	\N	{"code":"A-01","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
161	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	2	\N	{"code":"A-02","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
162	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	3	\N	{"code":"A-03","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
163	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	4	\N	{"code":"A-04","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
164	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	5	\N	{"code":"A-05","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
165	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	6	\N	{"code":"A-06","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
166	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	7	\N	{"code":"A-07","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
167	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	8	\N	{"code":"A-08","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:03
169	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	10	\N	{"code":"A-10","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:03
170	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	11	\N	{"code":"A-11","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:03
171	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	12	\N	{"code":"A-12","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:03
172	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	13	\N	{"code":"A-13","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:03
173	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	14	\N	{"code":"A-14","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:03
174	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	15	\N	{"code":"A-15","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:03
175	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	16	\N	{"code":"A-16","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":16}	127.0.0.1	Symfony	2026-05-03 17:07:03
176	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	17	\N	{"code":"A-17","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":17}	127.0.0.1	Symfony	2026-05-03 17:07:03
177	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	18	\N	{"code":"A-18","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":18}	127.0.0.1	Symfony	2026-05-03 17:07:03
178	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	19	\N	{"code":"A-19","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":19}	127.0.0.1	Symfony	2026-05-03 17:07:03
179	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	20	\N	{"code":"A-20","zone_id":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":20}	127.0.0.1	Symfony	2026-05-03 17:07:03
180	\N	created	App\\Modules\\Inventory\\Models\\WarehouseZone	2	\N	{"warehouse_id":1,"code":"B","name":"Zone B \\u2014 Staging","zone_type":"staging","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
181	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	21	\N	{"code":"B-01","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":21}	127.0.0.1	Symfony	2026-05-03 17:07:03
182	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	22	\N	{"code":"B-02","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":22}	127.0.0.1	Symfony	2026-05-03 17:07:03
183	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	23	\N	{"code":"B-03","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":23}	127.0.0.1	Symfony	2026-05-03 17:07:03
184	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	24	\N	{"code":"B-04","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":24}	127.0.0.1	Symfony	2026-05-03 17:07:03
185	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	25	\N	{"code":"B-05","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":25}	127.0.0.1	Symfony	2026-05-03 17:07:03
186	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	26	\N	{"code":"B-06","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":26}	127.0.0.1	Symfony	2026-05-03 17:07:03
187	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	27	\N	{"code":"B-07","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":27}	127.0.0.1	Symfony	2026-05-03 17:07:03
188	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	28	\N	{"code":"B-08","zone_id":2,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":28}	127.0.0.1	Symfony	2026-05-03 17:07:03
189	\N	created	App\\Modules\\Inventory\\Models\\WarehouseZone	3	\N	{"warehouse_id":1,"code":"C","name":"Zone C \\u2014 Finished Goods","zone_type":"finished_goods","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
190	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	29	\N	{"code":"C-01","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":29}	127.0.0.1	Symfony	2026-05-03 17:07:03
191	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	30	\N	{"code":"C-02","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":30}	127.0.0.1	Symfony	2026-05-03 17:07:03
192	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	31	\N	{"code":"C-03","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":31}	127.0.0.1	Symfony	2026-05-03 17:07:03
193	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	32	\N	{"code":"C-04","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":32}	127.0.0.1	Symfony	2026-05-03 17:07:03
194	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	33	\N	{"code":"C-05","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":33}	127.0.0.1	Symfony	2026-05-03 17:07:03
195	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	34	\N	{"code":"C-06","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":34}	127.0.0.1	Symfony	2026-05-03 17:07:03
196	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	35	\N	{"code":"C-07","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":35}	127.0.0.1	Symfony	2026-05-03 17:07:03
197	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	36	\N	{"code":"C-08","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":36}	127.0.0.1	Symfony	2026-05-03 17:07:03
198	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	37	\N	{"code":"C-09","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":37}	127.0.0.1	Symfony	2026-05-03 17:07:03
199	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	38	\N	{"code":"C-10","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":38}	127.0.0.1	Symfony	2026-05-03 17:07:03
200	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	39	\N	{"code":"C-11","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":39}	127.0.0.1	Symfony	2026-05-03 17:07:03
201	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	40	\N	{"code":"C-12","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":40}	127.0.0.1	Symfony	2026-05-03 17:07:03
202	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	41	\N	{"code":"C-13","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":41}	127.0.0.1	Symfony	2026-05-03 17:07:03
203	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	42	\N	{"code":"C-14","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":42}	127.0.0.1	Symfony	2026-05-03 17:07:03
204	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	43	\N	{"code":"C-15","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":43}	127.0.0.1	Symfony	2026-05-03 17:07:03
205	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	44	\N	{"code":"C-16","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":44}	127.0.0.1	Symfony	2026-05-03 17:07:03
206	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	45	\N	{"code":"C-17","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":45}	127.0.0.1	Symfony	2026-05-03 17:07:03
207	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	46	\N	{"code":"C-18","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":46}	127.0.0.1	Symfony	2026-05-03 17:07:03
208	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	47	\N	{"code":"C-19","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":47}	127.0.0.1	Symfony	2026-05-03 17:07:03
209	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	48	\N	{"code":"C-20","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":48}	127.0.0.1	Symfony	2026-05-03 17:07:03
210	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	49	\N	{"code":"C-21","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":49}	127.0.0.1	Symfony	2026-05-03 17:07:03
211	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	50	\N	{"code":"C-22","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":50}	127.0.0.1	Symfony	2026-05-03 17:07:03
212	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	51	\N	{"code":"C-23","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":51}	127.0.0.1	Symfony	2026-05-03 17:07:03
213	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	52	\N	{"code":"C-24","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":52}	127.0.0.1	Symfony	2026-05-03 17:07:03
214	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	53	\N	{"code":"C-25","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":53}	127.0.0.1	Symfony	2026-05-03 17:07:03
215	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	54	\N	{"code":"C-26","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":54}	127.0.0.1	Symfony	2026-05-03 17:07:03
216	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	55	\N	{"code":"C-27","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":55}	127.0.0.1	Symfony	2026-05-03 17:07:03
217	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	56	\N	{"code":"C-28","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":56}	127.0.0.1	Symfony	2026-05-03 17:07:03
218	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	57	\N	{"code":"C-29","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":57}	127.0.0.1	Symfony	2026-05-03 17:07:03
219	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	58	\N	{"code":"C-30","zone_id":3,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":58}	127.0.0.1	Symfony	2026-05-03 17:07:03
220	\N	created	App\\Modules\\Inventory\\Models\\WarehouseZone	4	\N	{"warehouse_id":1,"code":"D","name":"Zone D \\u2014 Spare Parts","zone_type":"spare_parts","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
221	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	59	\N	{"code":"D-01","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":59}	127.0.0.1	Symfony	2026-05-03 17:07:03
222	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	60	\N	{"code":"D-02","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":60}	127.0.0.1	Symfony	2026-05-03 17:07:03
223	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	61	\N	{"code":"D-03","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":61}	127.0.0.1	Symfony	2026-05-03 17:07:03
224	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	62	\N	{"code":"D-04","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":62}	127.0.0.1	Symfony	2026-05-03 17:07:03
225	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	63	\N	{"code":"D-05","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":63}	127.0.0.1	Symfony	2026-05-03 17:07:03
226	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	64	\N	{"code":"D-06","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":64}	127.0.0.1	Symfony	2026-05-03 17:07:03
227	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	65	\N	{"code":"D-07","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":65}	127.0.0.1	Symfony	2026-05-03 17:07:03
228	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	66	\N	{"code":"D-08","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":66}	127.0.0.1	Symfony	2026-05-03 17:07:03
229	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	67	\N	{"code":"D-09","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":67}	127.0.0.1	Symfony	2026-05-03 17:07:03
230	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	68	\N	{"code":"D-10","zone_id":4,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":68}	127.0.0.1	Symfony	2026-05-03 17:07:03
231	\N	created	App\\Modules\\Inventory\\Models\\WarehouseZone	5	\N	{"warehouse_id":1,"code":"Q","name":"Zone Q \\u2014 Quarantine","zone_type":"quarantine","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
232	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	69	\N	{"code":"Q-01","zone_id":5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":69}	127.0.0.1	Symfony	2026-05-03 17:07:03
233	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	70	\N	{"code":"Q-02","zone_id":5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":70}	127.0.0.1	Symfony	2026-05-03 17:07:03
234	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	71	\N	{"code":"Q-03","zone_id":5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":71}	127.0.0.1	Symfony	2026-05-03 17:07:03
235	\N	created	App\\Modules\\Inventory\\Models\\WarehouseLocation	72	\N	{"code":"Q-04","zone_id":5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":72}	127.0.0.1	Symfony	2026-05-03 17:07:03
236	\N	created	App\\Modules\\Accounting\\Models\\Customer	1	\N	{"name":"Toyota Motor Philippines, Inc.","contact_person":"Procurement Officer","email":null,"phone":null,"address":"Philippines","tin":"***","credit_limit":5000000,"payment_terms_days":30,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
237	\N	created	App\\Modules\\Accounting\\Models\\Customer	2	\N	{"name":"Nissan Philippines, Inc.","contact_person":"Procurement Officer","email":null,"phone":null,"address":"Philippines","tin":"***","credit_limit":3500000,"payment_terms_days":30,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
238	\N	created	App\\Modules\\Accounting\\Models\\Customer	3	\N	{"name":"Honda Philippines, Inc.","contact_person":"Procurement Officer","email":null,"phone":null,"address":"Philippines","tin":"***","credit_limit":4000000,"payment_terms_days":30,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
239	\N	created	App\\Modules\\Accounting\\Models\\Customer	4	\N	{"name":"Suzuki Philippines, Inc.","contact_person":"Procurement Officer","email":null,"phone":null,"address":"Philippines","tin":"***","credit_limit":2000000,"payment_terms_days":45,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
240	\N	created	App\\Modules\\Accounting\\Models\\Customer	5	\N	{"name":"Yamaha Motor Philippines, Inc.","contact_person":"Procurement Officer","email":null,"phone":null,"address":"Philippines","tin":"***","credit_limit":2500000,"payment_terms_days":45,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
241	\N	created	App\\Modules\\CRM\\Models\\Product	1	\N	{"part_number":"WB-001","name":"Wiper Bushing (Standard)","description":null,"unit_of_measure":"pcs","standard_cost":18.5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
242	\N	created	App\\Modules\\CRM\\Models\\Product	2	\N	{"part_number":"WB-002","name":"Wiper Bushing (Heavy Duty)","description":null,"unit_of_measure":"pcs","standard_cost":24.5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
243	\N	created	App\\Modules\\CRM\\Models\\Product	3	\N	{"part_number":"PC-001","name":"Pivot Cap Cover Type A","description":null,"unit_of_measure":"pcs","standard_cost":28,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
244	\N	created	App\\Modules\\CRM\\Models\\Product	4	\N	{"part_number":"PC-002","name":"Pivot Cap Cover Type B","description":null,"unit_of_measure":"pcs","standard_cost":28.5,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
245	\N	created	App\\Modules\\CRM\\Models\\Product	5	\N	{"part_number":"RC-001","name":"Relay Cover Standard","description":null,"unit_of_measure":"pcs","standard_cost":38,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
246	\N	created	App\\Modules\\CRM\\Models\\Product	6	\N	{"part_number":"BB-001","name":"Wiper Motor Bobbin","description":null,"unit_of_measure":"pcs","standard_cost":22,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
247	\N	created	App\\Modules\\CRM\\Models\\Product	7	\N	{"part_number":"BU-001","name":"Windshield Wiper Bushing","description":null,"unit_of_measure":"pcs","standard_cost":21,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
248	\N	created	App\\Modules\\CRM\\Models\\Product	8	\N	{"part_number":"RC-002","name":"Relay Cover Large","description":null,"unit_of_measure":"pcs","standard_cost":56,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:03
249	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	1	\N	{"product_id":1,"customer_id":1,"effective_from":"2026-01-01 00:00:00","price":22.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
250	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	2	\N	{"product_id":2,"customer_id":1,"effective_from":"2026-01-01 00:00:00","price":30,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
251	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	3	\N	{"product_id":3,"customer_id":1,"effective_from":"2026-01-01 00:00:00","price":35,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
252	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	4	\N	{"product_id":7,"customer_id":1,"effective_from":"2026-01-01 00:00:00","price":26,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
253	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	5	\N	{"product_id":1,"customer_id":2,"effective_from":"2026-01-01 00:00:00","price":23,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
254	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	6	\N	{"product_id":4,"customer_id":2,"effective_from":"2026-01-01 00:00:00","price":35.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
255	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	7	\N	{"product_id":5,"customer_id":2,"effective_from":"2026-01-01 00:00:00","price":47,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
256	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	8	\N	{"product_id":2,"customer_id":3,"effective_from":"2026-01-01 00:00:00","price":31,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:03
257	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	9	\N	{"product_id":3,"customer_id":3,"effective_from":"2026-01-01 00:00:00","price":35.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:03
258	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	10	\N	{"product_id":8,"customer_id":3,"effective_from":"2026-01-01 00:00:00","price":70,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:03
259	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	11	\N	{"product_id":6,"customer_id":4,"effective_from":"2026-01-01 00:00:00","price":28,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:03
260	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	12	\N	{"product_id":7,"customer_id":4,"effective_from":"2026-01-01 00:00:00","price":26.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:03
261	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	13	\N	{"product_id":6,"customer_id":5,"effective_from":"2026-01-01 00:00:00","price":28.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:03
262	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	14	\N	{"product_id":1,"customer_id":5,"effective_from":"2026-01-01 00:00:00","price":23.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:03
263	\N	created	App\\Modules\\CRM\\Models\\PriceAgreement	15	\N	{"product_id":5,"customer_id":5,"effective_from":"2026-01-01 00:00:00","price":47.5,"effective_to":"2026-12-31 00:00:00","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:03
264	\N	created	App\\Modules\\MRP\\Models\\Bom	1	\N	{"product_id":1,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
265	\N	created	App\\Modules\\MRP\\Models\\Bom	2	\N	{"product_id":2,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
266	\N	created	App\\Modules\\MRP\\Models\\Bom	3	\N	{"product_id":3,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
267	\N	created	App\\Modules\\MRP\\Models\\Bom	4	\N	{"product_id":4,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
268	\N	created	App\\Modules\\MRP\\Models\\Bom	5	\N	{"product_id":5,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
269	\N	created	App\\Modules\\MRP\\Models\\Bom	6	\N	{"product_id":6,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
270	\N	created	App\\Modules\\MRP\\Models\\Bom	7	\N	{"product_id":7,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
271	\N	created	App\\Modules\\MRP\\Models\\Bom	8	\N	{"product_id":8,"version":1,"is_active":true,"updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:03
272	\N	created	App\\Modules\\MRP\\Models\\Machine	1	\N	{"machine_code":"IMM-01","name":"Toshiba EC100SX","tonnage":100,"machine_type":"injection_molder","operators_required":1,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:03
273	\N	created	App\\Modules\\MRP\\Models\\Machine	2	\N	{"machine_code":"IMM-02","name":"Toshiba EC100SX","tonnage":100,"machine_type":"injection_molder","operators_required":1,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:03
274	\N	created	App\\Modules\\MRP\\Models\\Machine	3	\N	{"machine_code":"IMM-03","name":"Sumitomo SE130","tonnage":130,"machine_type":"injection_molder","operators_required":1,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:03
275	\N	created	App\\Modules\\MRP\\Models\\Machine	4	\N	{"machine_code":"IMM-04","name":"Sumitomo SE130","tonnage":130,"machine_type":"injection_molder","operators_required":1,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:03
276	\N	created	App\\Modules\\MRP\\Models\\Machine	5	\N	{"machine_code":"IMM-05","name":"Nissei NEX180","tonnage":180,"machine_type":"injection_molder","operators_required":1,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:03
277	\N	created	App\\Modules\\MRP\\Models\\Machine	6	\N	{"machine_code":"IMM-06","name":"Nissei NEX180","tonnage":180,"machine_type":"injection_molder","operators_required":1,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:03
278	\N	created	App\\Modules\\MRP\\Models\\Machine	7	\N	{"machine_code":"IMM-07","name":"Fanuc Roboshot","tonnage":220,"machine_type":"injection_molder","operators_required":1.5,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:03
279	\N	created	App\\Modules\\MRP\\Models\\Machine	8	\N	{"machine_code":"IMM-08","name":"Fanuc Roboshot","tonnage":220,"machine_type":"injection_molder","operators_required":1.5,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:03
280	\N	created	App\\Modules\\MRP\\Models\\Machine	9	\N	{"machine_code":"IMM-09","name":"JSW J280AD","tonnage":280,"machine_type":"injection_molder","operators_required":2,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:03
281	\N	created	App\\Modules\\MRP\\Models\\Machine	10	\N	{"machine_code":"IMM-10","name":"JSW J280AD","tonnage":280,"machine_type":"injection_molder","operators_required":2,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:03
282	\N	created	App\\Modules\\MRP\\Models\\Machine	11	\N	{"machine_code":"IMM-11","name":"Toshiba EC450","tonnage":450,"machine_type":"injection_molder","operators_required":2,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:03","created_at":"2026-05-03 17:07:03","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:04
283	\N	created	App\\Modules\\MRP\\Models\\Machine	12	\N	{"machine_code":"IMM-12","name":"Toshiba EC650","tonnage":650,"machine_type":"injection_molder","operators_required":2,"available_hours_per_day":16,"status":"idle","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:04
284	\N	created	App\\Modules\\MRP\\Models\\Mold	1	\N	{"mold_code":"M-WB-001","name":"WB-001 4-cav steel mold A","product_id":1,"cavity_count":4,"cycle_time_seconds":20,"output_rate_per_hour":720,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":100000,"lifetime_total_shots":0,"lifetime_max_shots":500000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
285	\N	created	App\\Modules\\MRP\\Models\\Mold	2	\N	{"mold_code":"M-WB-002","name":"WB-001 4-cav steel mold B","product_id":1,"cavity_count":4,"cycle_time_seconds":20,"output_rate_per_hour":720,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":100000,"lifetime_total_shots":0,"lifetime_max_shots":500000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
286	\N	created	App\\Modules\\MRP\\Models\\Mold	3	\N	{"mold_code":"M-WB-003","name":"WB-002 4-cav heavy duty","product_id":2,"cavity_count":4,"cycle_time_seconds":24,"output_rate_per_hour":600,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":80000,"lifetime_total_shots":0,"lifetime_max_shots":400000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
287	\N	created	App\\Modules\\MRP\\Models\\Mold	4	\N	{"mold_code":"M-PC-001","name":"PC-001 8-cav","product_id":3,"cavity_count":8,"cycle_time_seconds":30,"output_rate_per_hour":960,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":60000,"lifetime_total_shots":0,"lifetime_max_shots":300000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:04
288	\N	created	App\\Modules\\MRP\\Models\\Mold	5	\N	{"mold_code":"M-PC-002","name":"PC-001 8-cav backup","product_id":3,"cavity_count":8,"cycle_time_seconds":30,"output_rate_per_hour":960,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":60000,"lifetime_total_shots":0,"lifetime_max_shots":300000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:04
289	\N	created	App\\Modules\\MRP\\Models\\Mold	6	\N	{"mold_code":"M-PC-003","name":"PC-002 8-cav","product_id":4,"cavity_count":8,"cycle_time_seconds":30,"output_rate_per_hour":960,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":60000,"lifetime_total_shots":0,"lifetime_max_shots":300000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:04
290	\N	created	App\\Modules\\MRP\\Models\\Mold	7	\N	{"mold_code":"M-RC-001","name":"RC-001 2-cav","product_id":5,"cavity_count":2,"cycle_time_seconds":35,"output_rate_per_hour":205,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":50000,"lifetime_total_shots":0,"lifetime_max_shots":250000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:04
291	\N	created	App\\Modules\\MRP\\Models\\Mold	8	\N	{"mold_code":"M-RC-002","name":"RC-001 2-cav backup","product_id":5,"cavity_count":2,"cycle_time_seconds":35,"output_rate_per_hour":205,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":50000,"lifetime_total_shots":0,"lifetime_max_shots":250000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:04
292	\N	created	App\\Modules\\MRP\\Models\\Mold	9	\N	{"mold_code":"M-RC-003","name":"RC-002 2-cav large","product_id":8,"cavity_count":2,"cycle_time_seconds":45,"output_rate_per_hour":160,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":40000,"lifetime_total_shots":0,"lifetime_max_shots":200000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:04
293	\N	created	App\\Modules\\MRP\\Models\\Mold	10	\N	{"mold_code":"M-BB-001","name":"BB-001 4-cav bobbin","product_id":6,"cavity_count":4,"cycle_time_seconds":18,"output_rate_per_hour":800,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":80000,"lifetime_total_shots":0,"lifetime_max_shots":400000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:04
294	\N	created	App\\Modules\\MRP\\Models\\Mold	11	\N	{"mold_code":"M-BB-002","name":"BB-001 4-cav bobbin backup","product_id":6,"cavity_count":4,"cycle_time_seconds":18,"output_rate_per_hour":800,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":80000,"lifetime_total_shots":0,"lifetime_max_shots":400000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:04
295	\N	created	App\\Modules\\MRP\\Models\\Mold	12	\N	{"mold_code":"M-BU-001","name":"BU-001 6-cav","product_id":7,"cavity_count":6,"cycle_time_seconds":22,"output_rate_per_hour":981,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":70000,"lifetime_total_shots":0,"lifetime_max_shots":350000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:04
296	\N	created	App\\Modules\\MRP\\Models\\Mold	13	\N	{"mold_code":"M-BU-002","name":"BU-001 6-cav backup","product_id":7,"cavity_count":6,"cycle_time_seconds":22,"output_rate_per_hour":981,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":70000,"lifetime_total_shots":0,"lifetime_max_shots":350000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:04
297	\N	created	App\\Modules\\MRP\\Models\\Mold	14	\N	{"mold_code":"M-BU-003","name":"BU-001 6-cav backup 2","product_id":7,"cavity_count":6,"cycle_time_seconds":22,"output_rate_per_hour":981,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":70000,"lifetime_total_shots":0,"lifetime_max_shots":350000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:04
298	\N	created	App\\Modules\\MRP\\Models\\Mold	15	\N	{"mold_code":"M-WB-004","name":"WB-002 4-cav backup","product_id":2,"cavity_count":4,"cycle_time_seconds":24,"output_rate_per_hour":600,"setup_time_minutes":90,"current_shot_count":0,"max_shots_before_maintenance":80000,"lifetime_total_shots":0,"lifetime_max_shots":400000,"status":"available","location":"Tooling Crib \\u00b7 Bay A","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:04
299	\N	created	App\\Modules\\SupplyChain\\Models\\Vehicle	1	\N	{"plate_number":"TRK-001","name":"Truck 1","vehicle_type":"truck","capacity_kg":5000,"status":"available","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
300	\N	created	App\\Modules\\SupplyChain\\Models\\Vehicle	2	\N	{"plate_number":"TRK-002","name":"Truck 2","vehicle_type":"truck","capacity_kg":5000,"status":"available","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
301	\N	created	App\\Modules\\SupplyChain\\Models\\Vehicle	3	\N	{"plate_number":"VAN-001","name":"L300 Van","vehicle_type":"van","capacity_kg":1500,"status":"available","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
302	\N	created	App\\Modules\\HR\\Models\\Employee	1	\N	{"employee_no":"EMP-0001","first_name":"Maria","last_name":"Santos","birth_date":"1995-01-15 00:00:00","gender":"female","civil_status":"single","nationality":"Filipino","mobile_number":"+639170000000","email":"maria.santos@demo.local","sss_no":"***","philhealth_no":"***","pagibig_no":"***","tin":"***","department_id":2,"position_id":4,"employment_type":"regular","pay_type":"monthly","date_hired":"2023-01-15 00:00:00","date_regularized":"2023-07-15 00:00:00","basic_monthly_salary":65000,"daily_rate":null,"status":"active","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
303	\N	created	App\\Modules\\HR\\Models\\Employee	2	\N	{"employee_no":"EMP-0002","first_name":"Juan","last_name":"Dela Cruz","birth_date":"1996-03-10 00:00:00","gender":"male","civil_status":"single","nationality":"Filipino","mobile_number":"+639170000001","email":"juan.delacruz@demo.local","sss_no":"***","philhealth_no":"***","pagibig_no":"***","tin":"***","department_id":4,"position_id":9,"employment_type":"contractual","pay_type":"daily","date_hired":"2024-03-10 00:00:00","date_regularized":null,"basic_monthly_salary":null,"daily_rate":750,"status":"active","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
304	\N	created	App\\Modules\\HR\\Models\\Employee	3	\N	{"employee_no":"EMP-0003","first_name":"Ana","last_name":"Reyes","birth_date":"1995-08-01 00:00:00","gender":"female","civil_status":"single","nationality":"Filipino","mobile_number":"+639170000002","email":"ana.reyes@demo.local","sss_no":"***","philhealth_no":"***","pagibig_no":"***","tin":"***","department_id":5,"position_id":14,"employment_type":"regular","pay_type":"monthly","date_hired":"2023-08-01 00:00:00","date_regularized":"2024-02-01 00:00:00","basic_monthly_salary":32000,"daily_rate":null,"status":"active","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
305	\N	created	App\\Modules\\HR\\Models\\Employee	4	\N	{"employee_no":"EMP-0004","first_name":"Pedro","last_name":"Garcia","birth_date":"1996-06-20 00:00:00","gender":"male","civil_status":"single","nationality":"Filipino","mobile_number":"+639170000003","email":"pedro.garcia@demo.local","sss_no":"***","philhealth_no":"***","pagibig_no":"***","tin":"***","department_id":6,"position_id":18,"employment_type":"contractual","pay_type":"daily","date_hired":"2024-06-20 00:00:00","date_regularized":null,"basic_monthly_salary":null,"daily_rate":700,"status":"active","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:04
306	\N	created	App\\Modules\\HR\\Models\\Employee	5	\N	{"employee_no":"EMP-0005","first_name":"Liza","last_name":"Mendoza","birth_date":"1996-01-05 00:00:00","gender":"female","civil_status":"single","nationality":"Filipino","mobile_number":"+639170000004","email":"liza.mendoza@demo.local","sss_no":"***","philhealth_no":"***","pagibig_no":"***","tin":"***","department_id":3,"position_id":7,"employment_type":"regular","pay_type":"monthly","date_hired":"2024-01-05 00:00:00","date_regularized":"2024-07-05 00:00:00","basic_monthly_salary":38000,"daily_rate":null,"status":"active","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:04
307	\N	created	App\\Modules\\Accounting\\Models\\Vendor	1	\N	{"name":"Megaplast Industries Corp.","contact_person":"Account Manager","email":"sales@megaplast.ph","phone":"+632-8123-4567","address":"Metro Manila, Philippines","tin":"***","payment_terms_days":30,"is_active":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
308	\N	created	App\\Modules\\Accounting\\Models\\Vendor	2	\N	{"name":"Asia Pacific Polymers, Inc.","contact_person":"Account Manager","email":"orders@apolymers.ph","phone":"+632-8222-3344","address":"Metro Manila, Philippines","tin":"***","payment_terms_days":45,"is_active":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
309	\N	created	App\\Modules\\Accounting\\Models\\Vendor	3	\N	{"name":"Tooling Pro Manufacturing","contact_person":"Account Manager","email":"hello@toolingpro.ph","phone":"+632-8345-1122","address":"Metro Manila, Philippines","tin":"***","payment_terms_days":30,"is_active":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
310	\N	created	App\\Modules\\Accounting\\Models\\Vendor	4	\N	{"name":"Pacific Logistics Solutions","contact_person":"Account Manager","email":"support@paclogistics.ph","phone":"+632-8456-2244","address":"Metro Manila, Philippines","tin":"***","payment_terms_days":60,"is_active":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:04
311	\N	created	App\\Modules\\Attendance\\Models\\Attendance	1	\N	{"employee_id":1,"date":"2026-04-27 00:00:00","shift_id":null,"time_in":"2026-04-27 08:00:00","time_out":"2026-04-27 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
312	\N	created	App\\Modules\\Attendance\\Models\\Attendance	2	\N	{"employee_id":2,"date":"2026-04-27 00:00:00","shift_id":null,"time_in":"2026-04-27 08:00:00","time_out":"2026-04-27 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
313	\N	created	App\\Modules\\Attendance\\Models\\Attendance	3	\N	{"employee_id":3,"date":"2026-04-27 00:00:00","shift_id":null,"time_in":"2026-04-27 08:00:00","time_out":"2026-04-27 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
314	\N	created	App\\Modules\\Attendance\\Models\\Attendance	4	\N	{"employee_id":4,"date":"2026-04-27 00:00:00","shift_id":null,"time_in":"2026-04-27 08:00:00","time_out":"2026-04-27 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:04
315	\N	created	App\\Modules\\Attendance\\Models\\Attendance	5	\N	{"employee_id":5,"date":"2026-04-27 00:00:00","shift_id":null,"time_in":"2026-04-27 08:00:00","time_out":"2026-04-27 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:04
316	\N	created	App\\Modules\\Attendance\\Models\\Attendance	6	\N	{"employee_id":1,"date":"2026-04-28 00:00:00","shift_id":null,"time_in":"2026-04-28 08:00:00","time_out":"2026-04-28 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:04
317	\N	created	App\\Modules\\Attendance\\Models\\Attendance	7	\N	{"employee_id":2,"date":"2026-04-28 00:00:00","shift_id":null,"time_in":"2026-04-28 08:00:00","time_out":"2026-04-28 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:04
318	\N	created	App\\Modules\\Attendance\\Models\\Attendance	8	\N	{"employee_id":3,"date":"2026-04-28 00:00:00","shift_id":null,"time_in":"2026-04-28 08:00:00","time_out":"2026-04-28 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:04
319	\N	created	App\\Modules\\Attendance\\Models\\Attendance	9	\N	{"employee_id":4,"date":"2026-04-28 00:00:00","shift_id":null,"time_in":"2026-04-28 08:00:00","time_out":"2026-04-28 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:04
320	\N	created	App\\Modules\\Attendance\\Models\\Attendance	10	\N	{"employee_id":5,"date":"2026-04-28 00:00:00","shift_id":null,"time_in":"2026-04-28 08:00:00","time_out":"2026-04-28 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:04
321	\N	created	App\\Modules\\Attendance\\Models\\Attendance	11	\N	{"employee_id":1,"date":"2026-04-29 00:00:00","shift_id":null,"time_in":"2026-04-29 08:00:00","time_out":"2026-04-29 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:04
322	\N	created	App\\Modules\\Attendance\\Models\\Attendance	12	\N	{"employee_id":2,"date":"2026-04-29 00:00:00","shift_id":null,"time_in":"2026-04-29 08:00:00","time_out":"2026-04-29 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:04
323	\N	created	App\\Modules\\Attendance\\Models\\Attendance	13	\N	{"employee_id":3,"date":"2026-04-29 00:00:00","shift_id":null,"time_in":"2026-04-29 08:00:00","time_out":"2026-04-29 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:04
324	\N	created	App\\Modules\\Attendance\\Models\\Attendance	14	\N	{"employee_id":4,"date":"2026-04-29 00:00:00","shift_id":null,"time_in":"2026-04-29 08:00:00","time_out":"2026-04-29 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:04
325	\N	created	App\\Modules\\Attendance\\Models\\Attendance	15	\N	{"employee_id":5,"date":"2026-04-29 00:00:00","shift_id":null,"time_in":"2026-04-29 08:00:00","time_out":"2026-04-29 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:04
326	\N	created	App\\Modules\\Attendance\\Models\\Attendance	16	\N	{"employee_id":1,"date":"2026-04-30 00:00:00","shift_id":null,"time_in":"2026-04-30 08:00:00","time_out":"2026-04-30 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":16}	127.0.0.1	Symfony	2026-05-03 17:07:04
327	\N	created	App\\Modules\\Attendance\\Models\\Attendance	17	\N	{"employee_id":2,"date":"2026-04-30 00:00:00","shift_id":null,"time_in":"2026-04-30 08:00:00","time_out":"2026-04-30 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":17}	127.0.0.1	Symfony	2026-05-03 17:07:04
328	\N	created	App\\Modules\\Attendance\\Models\\Attendance	18	\N	{"employee_id":3,"date":"2026-04-30 00:00:00","shift_id":null,"time_in":"2026-04-30 08:00:00","time_out":"2026-04-30 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":18}	127.0.0.1	Symfony	2026-05-03 17:07:04
329	\N	created	App\\Modules\\Attendance\\Models\\Attendance	19	\N	{"employee_id":4,"date":"2026-04-30 00:00:00","shift_id":null,"time_in":"2026-04-30 08:00:00","time_out":"2026-04-30 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":19}	127.0.0.1	Symfony	2026-05-03 17:07:04
330	\N	created	App\\Modules\\Attendance\\Models\\Attendance	20	\N	{"employee_id":5,"date":"2026-04-30 00:00:00","shift_id":null,"time_in":"2026-04-30 08:00:00","time_out":"2026-04-30 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":20}	127.0.0.1	Symfony	2026-05-03 17:07:04
331	\N	created	App\\Modules\\Attendance\\Models\\Attendance	21	\N	{"employee_id":1,"date":"2026-05-01 00:00:00","shift_id":null,"time_in":"2026-05-01 08:00:00","time_out":"2026-05-01 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":21}	127.0.0.1	Symfony	2026-05-03 17:07:04
332	\N	created	App\\Modules\\Attendance\\Models\\Attendance	22	\N	{"employee_id":2,"date":"2026-05-01 00:00:00","shift_id":null,"time_in":"2026-05-01 08:00:00","time_out":"2026-05-01 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":22}	127.0.0.1	Symfony	2026-05-03 17:07:04
333	\N	created	App\\Modules\\Attendance\\Models\\Attendance	23	\N	{"employee_id":3,"date":"2026-05-01 00:00:00","shift_id":null,"time_in":"2026-05-01 08:00:00","time_out":"2026-05-01 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":23}	127.0.0.1	Symfony	2026-05-03 17:07:04
334	\N	created	App\\Modules\\Attendance\\Models\\Attendance	24	\N	{"employee_id":4,"date":"2026-05-01 00:00:00","shift_id":null,"time_in":"2026-05-01 08:00:00","time_out":"2026-05-01 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":24}	127.0.0.1	Symfony	2026-05-03 17:07:04
335	\N	created	App\\Modules\\Attendance\\Models\\Attendance	25	\N	{"employee_id":5,"date":"2026-05-01 00:00:00","shift_id":null,"time_in":"2026-05-01 08:00:00","time_out":"2026-05-01 17:00:00","regular_hours":8,"overtime_hours":0,"night_diff_hours":0,"tardiness_minutes":0,"undertime_minutes":0,"is_rest_day":false,"status":"present","is_manual_entry":true,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":25}	127.0.0.1	Symfony	2026-05-03 17:07:04
336	\N	created	App\\Modules\\CRM\\Models\\SalesOrder	1	\N	{"so_number":"SO-202605-0001","customer_id":1,"date":"2026-05-03 00:00:00","subtotal":2250,"vat_amount":270,"total_amount":2520,"status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #1","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
337	\N	updated	App\\Modules\\CRM\\Models\\SalesOrder	1	{"id":1,"so_number":"SO-202605-0001","customer_id":1,"date":"2026-05-02T16:00:00.000000Z","subtotal":"2250.00","vat_amount":"270.00","total_amount":"2520.00","status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #1","mrp_plan_id":null,"created_by":1,"created_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z"}	{"status":"confirmed"}	127.0.0.1	Symfony	2026-05-03 17:07:04
338	\N	created	App\\Modules\\MRP\\Models\\MrpPlan	1	\N	{"mrp_plan_no":"MRP-202605-0001","sales_order_id":1,"version":1,"status":"active","generated_by":1,"total_lines":1,"shortages_found":0,"auto_pr_count":0,"draft_wo_count":0,"diagnostics":"[]","generated_at":"2026-05-03 17:07:04","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
339	\N	created	App\\Modules\\Production\\Models\\WorkOrder	1	\N	{"wo_number":"WO-202605-0001","product_id":1,"sales_order_id":1,"sales_order_item_id":1,"mrp_plan_id":1,"parent_wo_id":null,"parent_ncr_id":null,"machine_id":null,"mold_id":null,"quantity_target":100,"planned_start":"2026-05-08 00:00:00","planned_end":"2026-05-09 00:00:00","priority":100,"status":"planned","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
340	\N	updated	App\\Modules\\MRP\\Models\\MrpPlan	1	{"mrp_plan_no":"MRP-202605-0001","sales_order_id":1,"version":1,"status":"active","generated_by":1,"total_lines":1,"shortages_found":0,"auto_pr_count":0,"draft_wo_count":0,"diagnostics":[],"generated_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z","created_at":"2026-05-03T09:07:04.000000Z","id":1}	{"draft_wo_count":1,"diagnostics":"[{\\"item_id\\":1,\\"item_code\\":\\"RM-001\\",\\"gross\\":1.58,\\"on_hand\\":237,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"},{\\"item_id\\":5,\\"item_code\\":\\"RM-010\\",\\"gross\\":0.2,\\"on_hand\\":785,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"},{\\"item_id\\":11,\\"item_code\\":\\"PKG-001\\",\\"gross\\":100,\\"on_hand\\":1607,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"}]"}	127.0.0.1	Symfony	2026-05-03 17:07:04
341	\N	updated	App\\Modules\\CRM\\Models\\SalesOrder	1	{"id":1,"so_number":"SO-202605-0001","customer_id":1,"date":"2026-05-02T16:00:00.000000Z","subtotal":"2250.00","vat_amount":"270.00","total_amount":"2520.00","status":"confirmed","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #1","mrp_plan_id":null,"created_by":1,"created_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z"}	{"mrp_plan_id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
342	\N	created	App\\Modules\\CRM\\Models\\SalesOrder	2	\N	{"so_number":"SO-202605-0002","customer_id":1,"date":"2026-05-01 00:00:00","subtotal":4500,"vat_amount":540,"total_amount":5040,"status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #2","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
343	\N	updated	App\\Modules\\CRM\\Models\\SalesOrder	2	{"id":2,"so_number":"SO-202605-0002","customer_id":1,"date":"2026-04-30T16:00:00.000000Z","subtotal":"4500.00","vat_amount":"540.00","total_amount":"5040.00","status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #2","mrp_plan_id":null,"created_by":1,"created_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z"}	{"status":"confirmed"}	127.0.0.1	Symfony	2026-05-03 17:07:04
344	\N	created	App\\Modules\\MRP\\Models\\MrpPlan	2	\N	{"mrp_plan_no":"MRP-202605-0002","sales_order_id":2,"version":1,"status":"active","generated_by":1,"total_lines":1,"shortages_found":0,"auto_pr_count":0,"draft_wo_count":0,"diagnostics":"[]","generated_at":"2026-05-03 17:07:04","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
345	\N	created	App\\Modules\\Production\\Models\\WorkOrder	2	\N	{"wo_number":"WO-202605-0002","product_id":2,"sales_order_id":2,"sales_order_item_id":2,"mrp_plan_id":2,"parent_wo_id":null,"parent_ncr_id":null,"machine_id":null,"mold_id":null,"quantity_target":150,"planned_start":"2026-05-11 00:00:00","planned_end":"2026-05-12 00:00:00","priority":100,"status":"planned","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
346	\N	updated	App\\Modules\\MRP\\Models\\MrpPlan	2	{"mrp_plan_no":"MRP-202605-0002","sales_order_id":2,"version":1,"status":"active","generated_by":1,"total_lines":1,"shortages_found":0,"auto_pr_count":0,"draft_wo_count":0,"diagnostics":[],"generated_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z","created_at":"2026-05-03T09:07:04.000000Z","id":2}	{"draft_wo_count":1,"diagnostics":"[{\\"item_id\\":1,\\"item_code\\":\\"RM-001\\",\\"gross\\":3.15,\\"on_hand\\":237,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"},{\\"item_id\\":5,\\"item_code\\":\\"RM-010\\",\\"gross\\":0.465,\\"on_hand\\":785,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"},{\\"item_id\\":11,\\"item_code\\":\\"PKG-001\\",\\"gross\\":150,\\"on_hand\\":1607,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"}]"}	127.0.0.1	Symfony	2026-05-03 17:07:04
347	\N	updated	App\\Modules\\CRM\\Models\\SalesOrder	2	{"id":2,"so_number":"SO-202605-0002","customer_id":1,"date":"2026-04-30T16:00:00.000000Z","subtotal":"4500.00","vat_amount":"540.00","total_amount":"5040.00","status":"confirmed","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #2","mrp_plan_id":null,"created_by":1,"created_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z"}	{"mrp_plan_id":2}	127.0.0.1	Symfony	2026-05-03 17:07:04
348	\N	created	App\\Modules\\CRM\\Models\\SalesOrder	3	\N	{"so_number":"SO-202605-0003","customer_id":1,"date":"2026-04-29 00:00:00","subtotal":7000,"vat_amount":840,"total_amount":7840,"status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #3","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
349	\N	updated	App\\Modules\\CRM\\Models\\SalesOrder	3	{"id":3,"so_number":"SO-202605-0003","customer_id":1,"date":"2026-04-28T16:00:00.000000Z","subtotal":"7000.00","vat_amount":"840.00","total_amount":"7840.00","status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #3","mrp_plan_id":null,"created_by":1,"created_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z"}	{"status":"confirmed"}	127.0.0.1	Symfony	2026-05-03 17:07:04
350	\N	created	App\\Modules\\MRP\\Models\\MrpPlan	3	\N	{"mrp_plan_no":"MRP-202605-0003","sales_order_id":3,"version":1,"status":"active","generated_by":1,"total_lines":1,"shortages_found":0,"auto_pr_count":0,"draft_wo_count":0,"diagnostics":"[]","generated_at":"2026-05-03 17:07:04","updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
351	\N	created	App\\Modules\\Production\\Models\\WorkOrder	3	\N	{"wo_number":"WO-202605-0003","product_id":3,"sales_order_id":3,"sales_order_item_id":3,"mrp_plan_id":3,"parent_wo_id":null,"parent_ncr_id":null,"machine_id":null,"mold_id":null,"quantity_target":200,"planned_start":"2026-05-14 00:00:00","planned_end":"2026-05-15 00:00:00","priority":100,"status":"planned","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
352	\N	updated	App\\Modules\\MRP\\Models\\MrpPlan	3	{"mrp_plan_no":"MRP-202605-0003","sales_order_id":3,"version":1,"status":"active","generated_by":1,"total_lines":1,"shortages_found":0,"auto_pr_count":0,"draft_wo_count":0,"diagnostics":[],"generated_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z","created_at":"2026-05-03T09:07:04.000000Z","id":3}	{"draft_wo_count":1,"diagnostics":"[{\\"item_id\\":2,\\"item_code\\":\\"RM-002\\",\\"gross\\":5.26,\\"on_hand\\":374,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"},{\\"item_id\\":6,\\"item_code\\":\\"RM-011\\",\\"gross\\":0.62,\\"on_hand\\":922,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"},{\\"item_id\\":11,\\"item_code\\":\\"PKG-001\\",\\"gross\\":200,\\"on_hand\\":1607,\\"reserved\\":0,\\"in_transit\\":0,\\"net\\":0,\\"action\\":\\"sufficient\\"}]"}	127.0.0.1	Symfony	2026-05-03 17:07:04
353	\N	updated	App\\Modules\\CRM\\Models\\SalesOrder	3	{"id":3,"so_number":"SO-202605-0003","customer_id":1,"date":"2026-04-28T16:00:00.000000Z","subtotal":"7000.00","vat_amount":"840.00","total_amount":"7840.00","status":"confirmed","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #3","mrp_plan_id":null,"created_by":1,"created_at":"2026-05-03T09:07:04.000000Z","updated_at":"2026-05-03T09:07:04.000000Z"}	{"mrp_plan_id":3}	127.0.0.1	Symfony	2026-05-03 17:07:04
354	\N	created	App\\Modules\\CRM\\Models\\SalesOrder	4	\N	{"so_number":"SO-202605-0004","customer_id":1,"date":"2026-04-27 00:00:00","subtotal":6500,"vat_amount":780,"total_amount":7280,"status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #4","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:04
355	\N	created	App\\Modules\\CRM\\Models\\SalesOrder	5	\N	{"so_number":"SO-202605-0005","customer_id":2,"date":"2026-04-25 00:00:00","subtotal":6900,"vat_amount":828,"total_amount":7728,"status":"draft","payment_terms_days":30,"delivery_terms":"Ex-Works (Cavite)","notes":"Demo seed \\u2014 order #5","created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:04
356	\N	created	App\\Modules\\CRM\\Models\\CustomerComplaint	1	\N	{"complaint_number":"CMP-202605-0001","customer_id":1,"product_id":1,"sales_order_id":1,"received_date":"2026-05-01 00:00:00","severity":"high","status":"open","description":"Customer reports surface scratches on a small batch of delivered units. Demo entry for showcasing the 8D workflow.","affected_quantity":12,"created_by":1,"updated_at":"2026-05-03 17:07:04","created_at":"2026-05-03 17:07:04","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:04
357	\N	created	App\\Modules\\Assets\\Models\\Asset	1	\N	{"asset_code":"AST-MCH-0001","name":"Toshiba EC100SX (IMM-01)","description":"Injection molding machine","category":"machine","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":6759624,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:05
358	\N	created	App\\Modules\\Assets\\Models\\Asset	2	\N	{"asset_code":"AST-MCH-0002","name":"Toshiba EC100SX (IMM-02)","description":"Injection molding machine","category":"machine","acquisition_date":"2018-05-03 00:00:00","acquisition_cost":4100210,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:05
359	\N	created	App\\Modules\\Assets\\Models\\Asset	3	\N	{"asset_code":"AST-MCH-0003","name":"Sumitomo SE130 (IMM-03)","description":"Injection molding machine","category":"machine","acquisition_date":"2020-05-03 00:00:00","acquisition_cost":2823969,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:05
360	\N	created	App\\Modules\\Assets\\Models\\Asset	4	\N	{"asset_code":"AST-MCH-0004","name":"Sumitomo SE130 (IMM-04)","description":"Injection molding machine","category":"machine","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":3944758,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:05
361	\N	created	App\\Modules\\Assets\\Models\\Asset	5	\N	{"asset_code":"AST-MCH-0005","name":"Nissei NEX180 (IMM-05)","description":"Injection molding machine","category":"machine","acquisition_date":"2020-05-03 00:00:00","acquisition_cost":4187635,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:05
362	\N	created	App\\Modules\\Assets\\Models\\Asset	6	\N	{"asset_code":"AST-MCH-0006","name":"Nissei NEX180 (IMM-06)","description":"Injection molding machine","category":"machine","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":6209490,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:05
363	\N	created	App\\Modules\\Assets\\Models\\Asset	7	\N	{"asset_code":"AST-MCH-0007","name":"Fanuc Roboshot (IMM-07)","description":"Injection molding machine","category":"machine","acquisition_date":"2021-05-03 00:00:00","acquisition_cost":7516023,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:05
364	\N	created	App\\Modules\\Assets\\Models\\Asset	8	\N	{"asset_code":"AST-MCH-0008","name":"Fanuc Roboshot (IMM-08)","description":"Injection molding machine","category":"machine","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":4135847,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:05
365	\N	created	App\\Modules\\Assets\\Models\\Asset	9	\N	{"asset_code":"AST-MCH-0009","name":"JSW J280AD (IMM-09)","description":"Injection molding machine","category":"machine","acquisition_date":"2025-05-03 00:00:00","acquisition_cost":2151210,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:05
366	\N	created	App\\Modules\\Assets\\Models\\Asset	10	\N	{"asset_code":"AST-MCH-0010","name":"JSW J280AD (IMM-10)","description":"Injection molding machine","category":"machine","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":7001428,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:05
367	\N	created	App\\Modules\\Assets\\Models\\Asset	11	\N	{"asset_code":"AST-MCH-0011","name":"Toshiba EC450 (IMM-11)","description":"Injection molding machine","category":"machine","acquisition_date":"2018-05-03 00:00:00","acquisition_cost":3738274,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":11}	127.0.0.1	Symfony	2026-05-03 17:07:05
368	\N	created	App\\Modules\\Assets\\Models\\Asset	12	\N	{"asset_code":"AST-MCH-0012","name":"Toshiba EC650 (IMM-12)","description":"Injection molding machine","category":"machine","acquisition_date":"2019-05-03 00:00:00","acquisition_cost":3847784,"useful_life_years":10,"salvage_value":100000,"status":"active","location":"Production Floor","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":12}	127.0.0.1	Symfony	2026-05-03 17:07:05
369	\N	created	App\\Modules\\Assets\\Models\\Asset	13	\N	{"asset_code":"AST-MLD-0001","name":"WB-001 4-cav steel mold A (M-WB-001)","description":"Injection mold","category":"mold","acquisition_date":"2022-05-03 00:00:00","acquisition_cost":902309,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":13}	127.0.0.1	Symfony	2026-05-03 17:07:05
370	\N	created	App\\Modules\\Assets\\Models\\Asset	14	\N	{"asset_code":"AST-MLD-0002","name":"WB-001 4-cav steel mold B (M-WB-002)","description":"Injection mold","category":"mold","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":729412,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":14}	127.0.0.1	Symfony	2026-05-03 17:07:05
371	\N	created	App\\Modules\\Assets\\Models\\Asset	15	\N	{"asset_code":"AST-MLD-0003","name":"WB-002 4-cav heavy duty (M-WB-003)","description":"Injection mold","category":"mold","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":1251603,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":15}	127.0.0.1	Symfony	2026-05-03 17:07:05
372	\N	created	App\\Modules\\Assets\\Models\\Asset	16	\N	{"asset_code":"AST-MLD-0004","name":"PC-001 8-cav (M-PC-001)","description":"Injection mold","category":"mold","acquisition_date":"2025-05-03 00:00:00","acquisition_cost":307872,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":16}	127.0.0.1	Symfony	2026-05-03 17:07:05
373	\N	created	App\\Modules\\Assets\\Models\\Asset	17	\N	{"asset_code":"AST-MLD-0005","name":"PC-001 8-cav backup (M-PC-002)","description":"Injection mold","category":"mold","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":908039,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":17}	127.0.0.1	Symfony	2026-05-03 17:07:05
374	\N	created	App\\Modules\\Assets\\Models\\Asset	18	\N	{"asset_code":"AST-MLD-0006","name":"PC-002 8-cav (M-PC-003)","description":"Injection mold","category":"mold","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":1133920,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":18}	127.0.0.1	Symfony	2026-05-03 17:07:05
375	\N	created	App\\Modules\\Assets\\Models\\Asset	19	\N	{"asset_code":"AST-MLD-0007","name":"RC-001 2-cav (M-RC-001)","description":"Injection mold","category":"mold","acquisition_date":"2022-05-03 00:00:00","acquisition_cost":1434043,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":19}	127.0.0.1	Symfony	2026-05-03 17:07:05
376	\N	created	App\\Modules\\Assets\\Models\\Asset	20	\N	{"asset_code":"AST-MLD-0008","name":"RC-001 2-cav backup (M-RC-002)","description":"Injection mold","category":"mold","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":1059238,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":20}	127.0.0.1	Symfony	2026-05-03 17:07:05
377	\N	created	App\\Modules\\Assets\\Models\\Asset	21	\N	{"asset_code":"AST-MLD-0009","name":"RC-002 2-cav large (M-RC-003)","description":"Injection mold","category":"mold","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":436581,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":21}	127.0.0.1	Symfony	2026-05-03 17:07:05
378	\N	created	App\\Modules\\Assets\\Models\\Asset	22	\N	{"asset_code":"AST-MLD-0010","name":"BB-001 4-cav bobbin (M-BB-001)","description":"Injection mold","category":"mold","acquisition_date":"2022-05-03 00:00:00","acquisition_cost":958119,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":22}	127.0.0.1	Symfony	2026-05-03 17:07:05
379	\N	created	App\\Modules\\Assets\\Models\\Asset	23	\N	{"asset_code":"AST-MLD-0011","name":"BB-001 4-cav bobbin backup (M-BB-002)","description":"Injection mold","category":"mold","acquisition_date":"2022-05-03 00:00:00","acquisition_cost":801553,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":23}	127.0.0.1	Symfony	2026-05-03 17:07:05
380	\N	created	App\\Modules\\Assets\\Models\\Asset	24	\N	{"asset_code":"AST-MLD-0012","name":"BU-001 6-cav (M-BU-001)","description":"Injection mold","category":"mold","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":868573,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":24}	127.0.0.1	Symfony	2026-05-03 17:07:05
381	\N	created	App\\Modules\\Assets\\Models\\Asset	25	\N	{"asset_code":"AST-MLD-0013","name":"BU-001 6-cav backup (M-BU-002)","description":"Injection mold","category":"mold","acquisition_date":"2023-05-03 00:00:00","acquisition_cost":719742,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":25}	127.0.0.1	Symfony	2026-05-03 17:07:05
382	\N	created	App\\Modules\\Assets\\Models\\Asset	26	\N	{"asset_code":"AST-MLD-0014","name":"BU-001 6-cav backup 2 (M-BU-003)","description":"Injection mold","category":"mold","acquisition_date":"2025-05-03 00:00:00","acquisition_cost":1486367,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":26}	127.0.0.1	Symfony	2026-05-03 17:07:05
383	\N	created	App\\Modules\\Assets\\Models\\Asset	27	\N	{"asset_code":"AST-MLD-0015","name":"WB-002 4-cav backup (M-WB-004)","description":"Injection mold","category":"mold","acquisition_date":"2022-05-03 00:00:00","acquisition_cost":1346740,"useful_life_years":5,"salvage_value":25000,"status":"active","location":"Mold Storage Bay","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":27}	127.0.0.1	Symfony	2026-05-03 17:07:05
384	\N	created	App\\Modules\\Assets\\Models\\Asset	28	\N	{"asset_code":"AST-VEH-0001","name":"Truck 1 (TRK-001)","description":"Delivery vehicle","category":"vehicle","acquisition_date":"2020-05-03 00:00:00","acquisition_cost":1456594,"useful_life_years":7,"salvage_value":50000,"status":"active","location":"Vehicle Yard","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":28}	127.0.0.1	Symfony	2026-05-03 17:07:05
385	\N	created	App\\Modules\\Assets\\Models\\Asset	29	\N	{"asset_code":"AST-VEH-0002","name":"Truck 2 (TRK-002)","description":"Delivery vehicle","category":"vehicle","acquisition_date":"2024-05-03 00:00:00","acquisition_cost":1138353,"useful_life_years":7,"salvage_value":50000,"status":"active","location":"Vehicle Yard","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":29}	127.0.0.1	Symfony	2026-05-03 17:07:05
386	\N	created	App\\Modules\\Assets\\Models\\Asset	30	\N	{"asset_code":"AST-VEH-0003","name":"L300 Van (VAN-001)","description":"Delivery vehicle","category":"vehicle","acquisition_date":"2020-05-03 00:00:00","acquisition_cost":1229683,"useful_life_years":7,"salvage_value":50000,"status":"active","location":"Vehicle Yard","updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":30}	127.0.0.1	Symfony	2026-05-03 17:07:05
387	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	1	\N	{"maintainable_type":"machine","maintainable_id":1,"description":"Quarterly preventive maintenance \\u2014 IMM-01","interval_type":"days","interval_value":90,"last_performed_at":"2026-03-04 17:07:05","next_due_at":"2026-05-16 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:05
388	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	2	\N	{"maintainable_type":"machine","maintainable_id":2,"description":"Quarterly preventive maintenance \\u2014 IMM-02","interval_type":"days","interval_value":90,"last_performed_at":"2026-02-13 17:07:05","next_due_at":"2026-07-19 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:05
389	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	3	\N	{"maintainable_type":"machine","maintainable_id":3,"description":"Quarterly preventive maintenance \\u2014 IMM-03","interval_type":"days","interval_value":90,"last_performed_at":"2026-02-19 17:07:05","next_due_at":"2026-05-30 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:05
390	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	4	\N	{"maintainable_type":"machine","maintainable_id":4,"description":"Quarterly preventive maintenance \\u2014 IMM-04","interval_type":"days","interval_value":90,"last_performed_at":"2026-03-31 17:07:05","next_due_at":"2026-06-20 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:05
391	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	5	\N	{"maintainable_type":"machine","maintainable_id":5,"description":"Quarterly preventive maintenance \\u2014 IMM-05","interval_type":"days","interval_value":90,"last_performed_at":"2026-04-11 17:07:05","next_due_at":"2026-07-17 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":5}	127.0.0.1	Symfony	2026-05-03 17:07:05
392	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	6	\N	{"maintainable_type":"machine","maintainable_id":6,"description":"Quarterly preventive maintenance \\u2014 IMM-06","interval_type":"days","interval_value":90,"last_performed_at":"2026-03-01 17:07:05","next_due_at":"2026-06-09 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":6}	127.0.0.1	Symfony	2026-05-03 17:07:05
393	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	7	\N	{"maintainable_type":"mold","maintainable_id":1,"description":"Shot-count refurbishment \\u2014 M-WB-001","interval_type":"shots","interval_value":100000,"last_performed_at":"2026-02-03 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":7}	127.0.0.1	Symfony	2026-05-03 17:07:05
394	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	8	\N	{"maintainable_type":"mold","maintainable_id":2,"description":"Shot-count refurbishment \\u2014 M-WB-002","interval_type":"shots","interval_value":100000,"last_performed_at":"2026-03-03 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":8}	127.0.0.1	Symfony	2026-05-03 17:07:05
395	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	9	\N	{"maintainable_type":"mold","maintainable_id":3,"description":"Shot-count refurbishment \\u2014 M-WB-003","interval_type":"shots","interval_value":80000,"last_performed_at":"2026-03-03 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":9}	127.0.0.1	Symfony	2026-05-03 17:07:05
396	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceSchedule	10	\N	{"maintainable_type":"mold","maintainable_id":4,"description":"Shot-count refurbishment \\u2014 M-PC-001","interval_type":"shots","interval_value":60000,"last_performed_at":"2026-03-03 17:07:05","is_active":true,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":10}	127.0.0.1	Symfony	2026-05-03 17:07:05
397	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceWorkOrder	1	\N	{"mwo_number":"MWO-202605-0001","maintainable_type":"machine","maintainable_id":1,"schedule_id":1,"type":"preventive","priority":"medium","description":"Quarterly preventive maintenance \\u2014 IMM-01","assigned_to":null,"status":"completed","started_at":"2026-05-02 17:07:05","completed_at":"2026-05-03 20:07:05","downtime_minutes":180,"cost":0,"created_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:05
398	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceWorkOrder	2	\N	{"mwo_number":"MWO-202605-0002","maintainable_type":"machine","maintainable_id":2,"schedule_id":2,"type":"preventive","priority":"medium","description":"Quarterly preventive maintenance \\u2014 IMM-02","assigned_to":null,"status":"in_progress","started_at":"2026-05-01 17:07:05","completed_at":null,"downtime_minutes":0,"cost":0,"created_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:05
399	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceWorkOrder	3	\N	{"mwo_number":"MWO-202605-0003","maintainable_type":"machine","maintainable_id":3,"schedule_id":3,"type":"preventive","priority":"medium","description":"Quarterly preventive maintenance \\u2014 IMM-03","assigned_to":null,"status":"in_progress","started_at":"2026-04-30 17:07:05","completed_at":null,"downtime_minutes":0,"cost":0,"created_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:05
400	\N	created	App\\Modules\\Maintenance\\Models\\MaintenanceWorkOrder	4	\N	{"mwo_number":"MWO-202605-0004","maintainable_type":"machine","maintainable_id":1,"type":"corrective","priority":"high","description":"Hydraulic pressure dropped during 2nd shift; investigate and repair.","assigned_to":null,"status":"open","created_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":4}	127.0.0.1	Symfony	2026-05-03 17:07:05
401	\N	created	App\\Modules\\HR\\Models\\Clearance	1	\N	{"clearance_no":"CLR-202605-0001","employee_id":1,"separation_date":"2026-05-17 00:00:00","separation_reason":"resigned","clearance_items":"[{\\"department\\":\\"Production\\",\\"item_key\\":\\"tools_returned\\",\\"label\\":\\"Tools returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Production\\",\\"item_key\\":\\"ppe_returned\\",\\"label\\":\\"PPE returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Warehouse\\",\\"item_key\\":\\"materials_returned\\",\\"label\\":\\"Materials returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Maintenance\\",\\"item_key\\":\\"no_pending_work\\",\\"label\\":\\"No pending maintenance work\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Finance\\",\\"item_key\\":\\"no_outstanding_ca\\",\\"label\\":\\"No outstanding cash advance\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"Finance\\",\\"item_key\\":\\"no_outstanding_loan\\",\\"label\\":\\"No outstanding company loan\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"id_returned\\",\\"label\\":\\"Company ID returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"file_201_complete\\",\\"label\\":\\"201 file complete\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"exit_interview_done\\",\\"label\\":\\"Exit interview done\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"IT\\",\\"item_key\\":\\"equipment_returned\\",\\"label\\":\\"IT equipment returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"IT\\",\\"item_key\\":\\"accounts_disabled\\",\\"label\\":\\"System accounts disabled\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null}]","status":"in_progress","initiated_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":1}	127.0.0.1	Symfony	2026-05-03 17:07:05
402	\N	created	App\\Modules\\HR\\Models\\Clearance	2	\N	{"clearance_no":"CLR-202605-0002","employee_id":2,"separation_date":"2026-04-26 00:00:00","separation_reason":"retired","clearance_items":"[{\\"department\\":\\"Production\\",\\"item_key\\":\\"tools_returned\\",\\"label\\":\\"Tools returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Production\\",\\"item_key\\":\\"ppe_returned\\",\\"label\\":\\"PPE returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Warehouse\\",\\"item_key\\":\\"materials_returned\\",\\"label\\":\\"Materials returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Maintenance\\",\\"item_key\\":\\"no_pending_work\\",\\"label\\":\\"No pending maintenance work\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Finance\\",\\"item_key\\":\\"no_outstanding_ca\\",\\"label\\":\\"No outstanding cash advance\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"Finance\\",\\"item_key\\":\\"no_outstanding_loan\\",\\"label\\":\\"No outstanding company loan\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"id_returned\\",\\"label\\":\\"Company ID returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"file_201_complete\\",\\"label\\":\\"201 file complete\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"exit_interview_done\\",\\"label\\":\\"Exit interview done\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"IT\\",\\"item_key\\":\\"equipment_returned\\",\\"label\\":\\"IT equipment returned\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null},{\\"department\\":\\"IT\\",\\"item_key\\":\\"accounts_disabled\\",\\"label\\":\\"System accounts disabled\\",\\"status\\":\\"cleared\\",\\"signed_by\\":1,\\"signed_at\\":\\"2026-05-03T17:07:05+08:00\\",\\"remarks\\":null}]","status":"completed","initiated_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":2}	127.0.0.1	Symfony	2026-05-03 17:07:05
403	\N	created	App\\Modules\\HR\\Models\\Clearance	3	\N	{"clearance_no":"CLR-202605-0003","employee_id":3,"separation_date":"2026-06-02 00:00:00","separation_reason":"end_of_contract","clearance_items":"[{\\"department\\":\\"Production\\",\\"item_key\\":\\"tools_returned\\",\\"label\\":\\"Tools returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"Production\\",\\"item_key\\":\\"ppe_returned\\",\\"label\\":\\"PPE returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"Warehouse\\",\\"item_key\\":\\"materials_returned\\",\\"label\\":\\"Materials returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"Maintenance\\",\\"item_key\\":\\"no_pending_work\\",\\"label\\":\\"No pending maintenance work\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"Finance\\",\\"item_key\\":\\"no_outstanding_ca\\",\\"label\\":\\"No outstanding cash advance\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"Finance\\",\\"item_key\\":\\"no_outstanding_loan\\",\\"label\\":\\"No outstanding company loan\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"id_returned\\",\\"label\\":\\"Company ID returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"file_201_complete\\",\\"label\\":\\"201 file complete\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"HR\\",\\"item_key\\":\\"exit_interview_done\\",\\"label\\":\\"Exit interview done\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"IT\\",\\"item_key\\":\\"equipment_returned\\",\\"label\\":\\"IT equipment returned\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null},{\\"department\\":\\"IT\\",\\"item_key\\":\\"accounts_disabled\\",\\"label\\":\\"System accounts disabled\\",\\"status\\":\\"pending\\",\\"signed_by\\":null,\\"signed_at\\":null,\\"remarks\\":null}]","status":"pending","initiated_by":1,"updated_at":"2026-05-03 17:07:05","created_at":"2026-05-03 17:07:05","id":3}	127.0.0.1	Symfony	2026-05-03 17:07:05
\.


--
-- Data for Name: bank_file_records; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.bank_file_records (id, payroll_period_id, file_path, record_count, total_amount, generated_by, generated_at, created_at) FROM stdin;
\.


--
-- Data for Name: bill_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.bill_items (id, bill_id, expense_account_id, description, quantity, unit, unit_price, total) FROM stdin;
\.


--
-- Data for Name: bill_of_materials; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.bill_of_materials (id, product_id, version, is_active, created_at, updated_at) FROM stdin;
1	1	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
2	2	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
3	3	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
4	4	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
5	5	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
6	6	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
7	7	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
8	8	1	t	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: bill_payments; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.bill_payments (id, bill_id, cash_account_id, payment_date, amount, payment_method, reference_number, journal_entry_id, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: bills; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.bills (id, bill_number, vendor_id, purchase_order_id, date, due_date, is_vatable, subtotal, vat_amount, total_amount, amount_paid, balance, status, journal_entry_id, created_by, remarks, created_at, updated_at, has_variances, three_way_match_snapshot, three_way_overridden, three_way_overridden_by, three_way_overridden_at, three_way_override_reason) FROM stdin;
\.


--
-- Data for Name: bom_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.bom_items (id, bom_id, item_id, quantity_per_unit, unit, waste_factor, sort_order) FROM stdin;
1	1	1	0.0150	kg	5.00	0
2	1	5	0.0020	kg	2.00	1
3	1	11	1.0000	pcs	0.00	2
4	2	1	0.0200	kg	5.00	0
5	2	5	0.0030	kg	2.00	1
6	2	11	1.0000	pcs	0.00	2
7	3	2	0.0250	kg	5.00	0
8	3	6	0.0030	kg	2.00	1
9	3	11	1.0000	pcs	0.00	2
10	4	2	0.0250	kg	5.00	0
11	4	7	0.0030	kg	2.00	1
12	4	11	1.0000	pcs	0.00	2
13	5	3	0.0300	kg	5.00	0
14	5	5	0.0020	kg	2.00	1
15	5	8	1.0000	pcs	0.50	2
16	5	11	1.0000	pcs	0.00	3
17	6	4	0.0120	kg	5.00	0
18	6	10	1.0000	pcs	0.50	1
19	6	11	1.0000	pcs	0.00	2
20	7	1	0.0180	kg	5.00	0
21	7	6	0.0020	kg	2.00	1
22	7	11	1.0000	pcs	0.00	2
23	8	3	0.0450	kg	5.00	0
24	8	5	0.0030	kg	2.00	1
25	8	9	2.0000	pcs	0.50	2
26	8	11	1.0000	pcs	0.00	3
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: clearances; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.clearances (id, clearance_no, employee_id, separation_date, separation_reason, clearance_items, final_pay_computed, final_pay_amount, final_pay_breakdown, journal_entry_id, status, initiated_by, finalized_at, finalized_by, remarks, created_at, updated_at, deleted_at) FROM stdin;
1	CLR-202605-0001	1	2026-05-17	resigned	[{"department":"Production","item_key":"tools_returned","label":"Tools returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Production","item_key":"ppe_returned","label":"PPE returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Warehouse","item_key":"materials_returned","label":"Materials returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Maintenance","item_key":"no_pending_work","label":"No pending maintenance work","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Finance","item_key":"no_outstanding_ca","label":"No outstanding cash advance","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"Finance","item_key":"no_outstanding_loan","label":"No outstanding company loan","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"HR","item_key":"id_returned","label":"Company ID returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"HR","item_key":"file_201_complete","label":"201 file complete","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"HR","item_key":"exit_interview_done","label":"Exit interview done","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"IT","item_key":"equipment_returned","label":"IT equipment returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"IT","item_key":"accounts_disabled","label":"System accounts disabled","status":"pending","signed_by":null,"signed_at":null,"remarks":null}]	f	\N	\N	\N	in_progress	1	\N	\N	\N	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
2	CLR-202605-0002	2	2026-04-26	retired	[{"department":"Production","item_key":"tools_returned","label":"Tools returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Production","item_key":"ppe_returned","label":"PPE returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Warehouse","item_key":"materials_returned","label":"Materials returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Maintenance","item_key":"no_pending_work","label":"No pending maintenance work","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Finance","item_key":"no_outstanding_ca","label":"No outstanding cash advance","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"Finance","item_key":"no_outstanding_loan","label":"No outstanding company loan","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"HR","item_key":"id_returned","label":"Company ID returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"HR","item_key":"file_201_complete","label":"201 file complete","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"HR","item_key":"exit_interview_done","label":"Exit interview done","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"IT","item_key":"equipment_returned","label":"IT equipment returned","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null},{"department":"IT","item_key":"accounts_disabled","label":"System accounts disabled","status":"cleared","signed_by":1,"signed_at":"2026-05-03T17:07:05+08:00","remarks":null}]	f	\N	\N	\N	completed	1	\N	\N	\N	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
3	CLR-202605-0003	3	2026-06-02	end_of_contract	[{"department":"Production","item_key":"tools_returned","label":"Tools returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"Production","item_key":"ppe_returned","label":"PPE returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"Warehouse","item_key":"materials_returned","label":"Materials returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"Maintenance","item_key":"no_pending_work","label":"No pending maintenance work","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"Finance","item_key":"no_outstanding_ca","label":"No outstanding cash advance","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"Finance","item_key":"no_outstanding_loan","label":"No outstanding company loan","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"HR","item_key":"id_returned","label":"Company ID returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"HR","item_key":"file_201_complete","label":"201 file complete","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"HR","item_key":"exit_interview_done","label":"Exit interview done","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"IT","item_key":"equipment_returned","label":"IT equipment returned","status":"pending","signed_by":null,"signed_at":null,"remarks":null},{"department":"IT","item_key":"accounts_disabled","label":"System accounts disabled","status":"pending","signed_by":null,"signed_at":null,"remarks":null}]	f	\N	\N	\N	pending	1	\N	\N	\N	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
\.


--
-- Data for Name: collections; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.collections (id, invoice_id, cash_account_id, collection_date, amount, payment_method, reference_number, journal_entry_id, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: complaint_8d_reports; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.complaint_8d_reports (id, complaint_id, d1_team, d2_problem, d3_containment, d4_root_cause, d5_corrective_action, d6_verification, d7_prevention, d8_recognition, finalized_by, finalized_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: customer_complaints; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.customer_complaints (id, complaint_number, customer_id, product_id, sales_order_id, received_date, severity, status, description, affected_quantity, ncr_id, replacement_work_order_id, credit_memo_id, created_by, assigned_to, resolved_at, closed_at, created_at, updated_at) FROM stdin;
1	CMP-202605-0001	1	1	1	2026-05-01	high	open	Customer reports surface scratches on a small batch of delivered units. Demo entry for showcasing the 8D workflow.	12	\N	\N	\N	1	\N	\N	\N	2026-05-03 17:07:04	2026-05-03 17:07:04
\.


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.customers (id, name, contact_person, email, phone, address, tin, credit_limit, payment_terms_days, is_active, created_at, updated_at, deleted_at) FROM stdin;
1	Toyota Motor Philippines, Inc.	Procurement Officer	\N	\N	Philippines	eyJpdiI6InFjclE3R2FRL1ZOU2U0M0lpY3ZXNHc9PSIsInZhbHVlIjoiUi9xaFAvS2J3YkVCN0tIVkg1azZqdz09IiwibWFjIjoiNzJmMmQ0YjFjYjlmYWVhMWNmMDYxZjU3MzM0NzZiMmZmYjFlZjMxYjcwMzhkZjZhNjE3MDVlNGM3OTVhYjRiNSIsInRhZyI6IiJ9	5000000.00	30	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
2	Nissan Philippines, Inc.	Procurement Officer	\N	\N	Philippines	eyJpdiI6Imt0N3Zld2Y1YXJoRmRmNEg0VWg4QUE9PSIsInZhbHVlIjoicXVIeGRuc0lrSUZNREhMK1doZ1hhdz09IiwibWFjIjoiZTAwMDJjODMyYWFkOTZjZTQwOGMzNzZkMGUxZDBhODNkZDkyNGEyOWRjNTQ2YzVhYjIwMzJiOGY4MzBlMDQ4MSIsInRhZyI6IiJ9	3500000.00	30	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
3	Honda Philippines, Inc.	Procurement Officer	\N	\N	Philippines	eyJpdiI6ImxLSUdhZU9zeHJpUWtWd1JYeGtTUGc9PSIsInZhbHVlIjoibXp1T2IrU0JzMTJCRkdnSi8wY09wZz09IiwibWFjIjoiMzhhYzQyNmIyODRjYzYzY2RkNjRmM2MyNjUxYzY3MWViZmM3MDZhOTkyNGZhZjQ5ODk2NDhlZTUxMWNjMzJkYiIsInRhZyI6IiJ9	4000000.00	30	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
4	Suzuki Philippines, Inc.	Procurement Officer	\N	\N	Philippines	eyJpdiI6IkZVYml4cmt1citNSWVUSWJaaUgweEE9PSIsInZhbHVlIjoiRlF3YkV5RVcyRnoydUQ4cU1ocG1Jdz09IiwibWFjIjoiZDllM2Y1MmI5N2FlZWE1OWIzMzA2MGI0MzgxYjNjODE2OGQ2MTRhYWM3MzlhZjBkMzQyYjAxYzc0MjEzMzkzZiIsInRhZyI6IiJ9	2000000.00	45	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
5	Yamaha Motor Philippines, Inc.	Procurement Officer	\N	\N	Philippines	eyJpdiI6Ik9GUkQ5R3BmUnY5WS9TM1B1VWtOdHc9PSIsInZhbHVlIjoiZWM3bk5yQlhuYk9EWklPbllHMEtVZz09IiwibWFjIjoiM2FlYzVkMzlkYzAzNDQ1MjI3NGMxMDdlZGVkN2Q5NzdiY2YzZTI5ODhiYWMzNmIwMTZkMjA4NWQ4NzMwNDEyNyIsInRhZyI6IiJ9	2500000.00	45	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
\.


--
-- Data for Name: defect_types; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.defect_types (id, code, name, description, is_active, created_at, updated_at) FROM stdin;
1	SHRT	Short Shot	Incomplete fill of cavity.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
2	FLSH	Flash	Excess material at parting line.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
3	BURN	Burn Marks	Discoloration from trapped air or overheating.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
4	DIM	Dimensional	Out-of-spec dimensions.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
5	COLOR	Color Mismatch	Color does not match standard.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
6	CRACK	Cracks	Visible cracks in part.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
7	WARP	Warpage	Distorted from intended geometry.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
8	BUBBLE	Air Bubbles	Internal bubbles / voids.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
9	INC	Inclusions	Foreign matter embedded in part.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
10	MISMATCH	Mold Mismatch	Mold halves misaligned.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
11	OTHER	Other	Catch-all.	t	2026-05-03 17:07:04	2026-05-03 17:07:04
\.


--
-- Data for Name: deliveries; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.deliveries (id, delivery_number, sales_order_id, vehicle_id, driver_id, status, scheduled_date, departed_at, delivered_at, confirmed_at, confirmed_by, receipt_photo_path, invoice_id, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: delivery_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.delivery_items (id, delivery_id, sales_order_item_id, inspection_id, quantity, unit_price, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: departments; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.departments (id, name, code, parent_id, head_employee_id, is_active, created_at, updated_at) FROM stdin;
1	Executive	EXEC	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
2	Human Resources	HR	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
3	Finance & Accounting	FIN	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
4	Production	PROD	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
5	Quality Control	QC	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
6	Warehouse & Logistics	WH	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
7	Purchasing & Procurement	PUR	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
8	Production Planning	PPC	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
9	Maintenance & Engineering	MAINT	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
10	Mold Department	MOLD	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
11	Import/Export	IMPEX	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
12	Admin & General Affairs	ADMIN	\N	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: document_sequences; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.document_sequences (id, document_type, prefix, year, month, last_number) FROM stdin;
2	mrp_plan	MRP	2026	5	3
3	work_order	WO	2026	5	3
1	sales_order	SO	2026	5	5
4	complaint	CMP	2026	5	1
5	maintenance_wo	MWO	2026	5	4
6	clearance	CLR	2026	5	3
\.


--
-- Data for Name: employee_documents; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employee_documents (id, employee_id, document_type, file_name, file_path, uploaded_at, created_at) FROM stdin;
\.


--
-- Data for Name: employee_leave_balances; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employee_leave_balances (id, employee_id, leave_type_id, year, total_credits, used, remaining, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: employee_loans; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employee_loans (id, loan_no, employee_id, loan_type, principal, interest_rate, monthly_amortization, total_paid, balance, start_date, end_date, pay_periods_total, pay_periods_remaining, approval_chain_size, purpose, status, is_final_pay_deduction, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: employee_property; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employee_property (id, employee_id, item_name, description, quantity, date_issued, date_returned, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: employee_shift_assignments; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employee_shift_assignments (id, employee_id, shift_id, effective_date, end_date, created_at) FROM stdin;
\.


--
-- Data for Name: employees; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employees (id, employee_no, first_name, middle_name, last_name, suffix, birth_date, gender, civil_status, nationality, photo_path, street_address, barangay, city, province, zip_code, mobile_number, email, emergency_contact_name, emergency_contact_relation, emergency_contact_phone, sss_no, philhealth_no, pagibig_no, tin, department_id, position_id, employment_type, pay_type, date_hired, date_regularized, basic_monthly_salary, daily_rate, bank_name, bank_account_no, status, created_at, updated_at, deleted_at) FROM stdin;
1	EMP-0001	Maria	\N	Santos	\N	1995-01-15	female	single	Filipino	\N	\N	\N	\N	\N	\N	+639170000000	maria.santos@demo.local	\N	\N	\N	eyJpdiI6IlJYa1ZMNkZCd0ozeDlVRXZYN2FxUXc9PSIsInZhbHVlIjoiOUQ0U0dpQ3JnREk2a0JLcDAzdjZFUT09IiwibWFjIjoiZjJkZTUwOTc4ZThhYzk5YjVmNmUyYzUyYWQyMDM4ZDUwMDNkMjI3MWZmZjYwZGMzMmVjYjVmNDBmMTFlZjhkZCIsInRhZyI6IiJ9	eyJpdiI6IjlrcVJlYkNNNTFXYklySzFxYjQ5WGc9PSIsInZhbHVlIjoiUUdMUWcvcGtIU0paQTUwWUxHWU5kQT09IiwibWFjIjoiZmEwNTVhM2JkODFkYjEwNzYxYzk5MWY3YzRiMzZlMGNlZDdkYTI0NmZjOGFhOTlkZDc1NmQ0MzRhMjkwOGYzOCIsInRhZyI6IiJ9	eyJpdiI6IkllV3ZKanF1dGhBb1NmZEU5T09hREE9PSIsInZhbHVlIjoiQ1NyampBcGJ4MjhPS00xamZWb3AyUT09IiwibWFjIjoiZTZiNGQ2ZDk0YmZmMWMzNmFjMDAwOTk5NTAwNDBlMDJhYjA4OTQwOTg5MmYxYzQyNGIyZGI0MGZhOWFiYjVmNiIsInRhZyI6IiJ9	eyJpdiI6IlBvaXM5bEkwYjFaaGhLNGxsWGpHeHc9PSIsInZhbHVlIjoiVVRxKzRtdStJMHJrYlYyUnVGQWJXdz09IiwibWFjIjoiYzBjMmE5ZTIwYTQ3NDZiMmVjYmVlY2ZjYTk1ZDdjYTc4ZjM2MzY4Y2MwYTc1YzI2MDA4ZTdjMmY2ZGVmODMzYyIsInRhZyI6IiJ9	2	4	regular	monthly	2023-01-15	2023-07-15	65000.00	\N	\N	\N	active	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
2	EMP-0002	Juan	\N	Dela Cruz	\N	1996-03-10	male	single	Filipino	\N	\N	\N	\N	\N	\N	+639170000001	juan.delacruz@demo.local	\N	\N	\N	eyJpdiI6Ikl0ZDFDbmtRN1paNGcvdi9tWVh1K1E9PSIsInZhbHVlIjoiSkxUMklSaDREWWRiZTd3V0hGdUhKUT09IiwibWFjIjoiMDIwODU0ZGM4YjNiOTQ0MGU2NzEyMGEzODgxOGIxNTY0YjkyYjA3ZjFkZWU0YThmYzJiY2M3ZjE4YzEzYjNhOSIsInRhZyI6IiJ9	eyJpdiI6IlV6UzJtd1YwRzNJRFV6YnVzRVpUZlE9PSIsInZhbHVlIjoiczg1Z1RuaTBaTXNBaUs5eWxHaE5Gdz09IiwibWFjIjoiYzkyOWIxMjJhNjJhODZhYTAzNzlmZGVhYzBjMzAxMGZiZjNiY2NlY2MxMDUyOGU3Y2EzYWY3NWE1YzFlZWE2MiIsInRhZyI6IiJ9	eyJpdiI6IlZPR0JCaXN3RjlLdGlldnFUdjVNWnc9PSIsInZhbHVlIjoiUE02Rkl3U085VDhSVUxFMXJWUXFKUT09IiwibWFjIjoiOTNmMDJkMDFhNDU0MmFhM2U4YjUxNGJlZDg5YWM0ODZkNGVjZjQ5NzgxODRhZDIxYWYzMTFhYjcyM2MzYTVhOSIsInRhZyI6IiJ9	eyJpdiI6IkZWYzFSTFAxMWYyMFp6L1Fub2VlbHc9PSIsInZhbHVlIjoiZFJSb2hTcWtYcEo4TENXOG1mVCtjQT09IiwibWFjIjoiZWE5N2ZlNDVhMjg5ZTcwNWIwNmY5MGJhNmQ4MzczNTE0MjM3YWFhYTgyNjliZTdhYjkyZGY5MzYyMzA1YTMzNiIsInRhZyI6IiJ9	4	9	contractual	daily	2024-03-10	\N	\N	750.00	\N	\N	active	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
3	EMP-0003	Ana	\N	Reyes	\N	1995-08-01	female	single	Filipino	\N	\N	\N	\N	\N	\N	+639170000002	ana.reyes@demo.local	\N	\N	\N	eyJpdiI6IlpVN1k4czZYWjhZcWhKc2lYUkc0T3c9PSIsInZhbHVlIjoiMFB5OGJ3OVNPZTQ3bytQcU9RWk8xQT09IiwibWFjIjoiZGY1OTJmZWZhMmQyZTk3NTQ3ZTgzODI0ZGIxMmVlOTA1MGYyMWRiYWNhMmY3MTA2ODNlYzNhMWFjODA1ZGU1NSIsInRhZyI6IiJ9	eyJpdiI6IjRjWEsxSlJNemJiN3BtREZRZGJYc1E9PSIsInZhbHVlIjoiM3JQL0ZvVk02M28xM0t4SGNxTExxQT09IiwibWFjIjoiZGE4NzIxZjEyZWNhZjk3YWJhMjcyOTcxMDRiMGUzODAyYjE3YTQ0MzEwOTI4MzIxMDFhMmVlNzJiZDIyMDUxZiIsInRhZyI6IiJ9	eyJpdiI6IlY3Y3YrNndWd0twTFBDdUYvQmx0aHc9PSIsInZhbHVlIjoiZXlIN0h4NTE4SGxoWEY0SytaYU5wZz09IiwibWFjIjoiY2ZmZTI5Mjg1OTUyYTA4NDc4N2RmMzZjZTVmYzZjZjNhMzY3ZTc1YjliNjI4MWVjOTI0Y2YzNjlkNjRkMGJkNCIsInRhZyI6IiJ9	eyJpdiI6ImJtcVlhNlYwZDdBQ0JNM2lVdHJEYkE9PSIsInZhbHVlIjoiWEg5cFlzZDhKVGRIdjFzeXRyMGRUUT09IiwibWFjIjoiNzFlNjVhNGEwMzhiZjdjYjNiZWY0NGRjZjllZmNkZGVhY2Y1NzgwZGU4YjU1NmEwODJlNTFiZDgwM2Y3YTYyOSIsInRhZyI6IiJ9	5	14	regular	monthly	2023-08-01	2024-02-01	32000.00	\N	\N	\N	active	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
4	EMP-0004	Pedro	\N	Garcia	\N	1996-06-20	male	single	Filipino	\N	\N	\N	\N	\N	\N	+639170000003	pedro.garcia@demo.local	\N	\N	\N	eyJpdiI6IlpXbENSazZOcEwxQ09IOGFCR1VzRXc9PSIsInZhbHVlIjoiL3NRbEFWdDJPaEpHMlBXYlZmWFMvdz09IiwibWFjIjoiZWIzMjM2ODk2YWEyZDIyMWM0Zjc4MDdkZDMyNWNhZDU1YmQ3ZGQ3Mzc1ZGZiMzA4YmE5ZWM4MzVmNTllZDQ3NiIsInRhZyI6IiJ9	eyJpdiI6ImJIK3Y2UUczS3F3RFNZZGZCV01NTGc9PSIsInZhbHVlIjoicUczM2dRQWVDQkU1allOK1czVE82QT09IiwibWFjIjoiZDA3MDg3ZWFmMTc0OTUyYjQ4NzYzMGE4MTI4ZGI4ZTJmNDJlZTdiNDI3N2U0NGMxNDYxZmY5OTA2ZGI2MGY2NSIsInRhZyI6IiJ9	eyJpdiI6InVSNHhsTDZiM21OaUFjUDc5UDB6T3c9PSIsInZhbHVlIjoiUkVPZUZKYXgyWlMvUUg1QW5veEM2dz09IiwibWFjIjoiNzNlMTUzMzQ3NzVjY2QwZTg5NGI1MDQ1MWUzNDBiZGYyNTFjMTlmN2YxOTM2MDE5Y2UxNTU0OTkxZDY5OGVhYSIsInRhZyI6IiJ9	eyJpdiI6InJCeWdLSHE1WlJPVTlWZzU2eG44bGc9PSIsInZhbHVlIjoiZkszb2ROUUZYVFgvOE96MDAwSUtUZz09IiwibWFjIjoiMjc5ZjQ0ZDc4OThlNjMzNTI3M2M5ZTAyZTE5NjFiZmM2ZmJlNGI0MGQ1OTQyN2M1ZjM2OGE1YTQyM2VhNTcxMiIsInRhZyI6IiJ9	6	18	contractual	daily	2024-06-20	\N	\N	700.00	\N	\N	active	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
5	EMP-0005	Liza	\N	Mendoza	\N	1996-01-05	female	single	Filipino	\N	\N	\N	\N	\N	\N	+639170000004	liza.mendoza@demo.local	\N	\N	\N	eyJpdiI6Ii9IUlNCd0IyL28wWVNmQlJWeHFmYUE9PSIsInZhbHVlIjoiQldyWnBuRG1FV3l0d0JKODltNEpXUT09IiwibWFjIjoiZTYwZDJjZGM3YmIxMWYxZDk4MjY0MTkxNTkyNTcxNjBhMjI3YTc4MTgxZjM4OWIyYTc4ZTRiODI4MzhlMzYyNyIsInRhZyI6IiJ9	eyJpdiI6IlcxNE9TNW5yaVA1ZmFFRDJURnFCOXc9PSIsInZhbHVlIjoiaHdwcWFPaVZSNGZLL2cza1FlZi9sQT09IiwibWFjIjoiMzQwYWE4ZTk1NmRhODdlYjJiYzc5MzhjOTA2MjA4N2M3YjMyYWFiODNjMmY1OTRmYzY0MjlhNWZmYTRjMjM3OSIsInRhZyI6IiJ9	eyJpdiI6IkdHbG9hcG5PdG8vRXhMU0FZNkJ1bEE9PSIsInZhbHVlIjoiVEd6VGtTWlRKdk1JMThLS21lMnhqZz09IiwibWFjIjoiZmJhZWIxY2YzODk3YjM5ZWI4NzI3YWJkZjZlMGVmOTE1M2U2NzFjMzk0YmFjNGM5OTQzNDYzNzNmNmQ1MmY3NSIsInRhZyI6IiJ9	eyJpdiI6InliRFQwMjRzdmlHdXpmSjFqclhDQlE9PSIsInZhbHVlIjoiQlR4ZXVqUjZzNzRDcmUzZ0plZ2Iwdz09IiwibWFjIjoiZTcyZThjMGE4Yzk5NDZmZTU1NjgzNzQ1MjYwMWFjYTI2MGNhZDExOTNkZTA1MDM0OWUwOWMxZWYxZDU2N2M3NyIsInRhZyI6IiJ9	3	7	regular	monthly	2024-01-05	2024-07-05	38000.00	\N	\N	\N	active	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
\.


--
-- Data for Name: employment_history; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.employment_history (id, employee_id, change_type, from_value, to_value, effective_date, remarks, approved_by, created_at) FROM stdin;
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: goods_receipt_notes; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.goods_receipt_notes (id, grn_number, purchase_order_id, vendor_id, received_date, received_by, status, qc_inspection_id, accepted_by, accepted_at, rejected_reason, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: government_contribution_tables; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.government_contribution_tables (id, agency, bracket_min, bracket_max, ee_amount, er_amount, effective_date, is_active, created_at, updated_at) FROM stdin;
1	sss	0.00	4249.99	180.0000	390.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
2	sss	4250.00	4749.99	202.5000	437.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
3	sss	4750.00	5249.99	225.0000	485.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
4	sss	5250.00	5749.99	247.5000	532.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
5	sss	5750.00	6249.99	270.0000	580.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
6	sss	6250.00	6749.99	292.5000	627.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
7	sss	6750.00	7249.99	315.0000	675.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
8	sss	7250.00	7749.99	337.5000	722.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
9	sss	7750.00	8249.99	360.0000	770.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
10	sss	8250.00	8749.99	382.5000	817.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
11	sss	8750.00	9249.99	405.0000	865.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
12	sss	9250.00	9749.99	427.5000	912.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
13	sss	9750.00	10249.99	450.0000	960.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
14	sss	10250.00	10749.99	472.5000	1007.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
15	sss	10750.00	11249.99	495.0000	1055.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
16	sss	11250.00	11749.99	517.5000	1102.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
17	sss	11750.00	12249.99	540.0000	1150.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
18	sss	12250.00	12749.99	562.5000	1197.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
19	sss	12750.00	13249.99	585.0000	1245.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
20	sss	13250.00	13749.99	607.5000	1292.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
21	sss	13750.00	14249.99	630.0000	1340.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
22	sss	14250.00	14749.99	652.5000	1387.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
23	sss	14750.00	15249.99	675.0000	1435.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
24	sss	15250.00	15749.99	697.5000	1482.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
25	sss	15750.00	16249.99	720.0000	1530.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
26	sss	16250.00	16749.99	742.5000	1577.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
27	sss	16750.00	17249.99	765.0000	1625.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
28	sss	17250.00	17749.99	787.5000	1672.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
29	sss	17750.00	18249.99	810.0000	1720.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
30	sss	18250.00	18749.99	832.5000	1767.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
31	sss	18750.00	19249.99	855.0000	1815.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
32	sss	19250.00	19749.99	877.5000	1862.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
33	sss	19750.00	20249.99	900.0000	1910.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
34	sss	20250.00	20749.99	922.5000	1957.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
35	sss	20750.00	21249.99	945.0000	2005.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
36	sss	21250.00	21749.99	967.5000	2052.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
37	sss	21750.00	22249.99	990.0000	2100.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
38	sss	22250.00	22749.99	1012.5000	2147.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
39	sss	22750.00	23249.99	1035.0000	2195.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
40	sss	23250.00	23749.99	1057.5000	2242.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
41	sss	23750.00	24249.99	1080.0000	2290.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
42	sss	24250.00	24749.99	1102.5000	2337.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
43	sss	24750.00	25249.99	1125.0000	2385.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
44	sss	25250.00	25749.99	1147.5000	2432.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
45	sss	25750.00	26249.99	1170.0000	2480.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
46	sss	26250.00	26749.99	1192.5000	2527.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
47	sss	26750.00	27249.99	1215.0000	2575.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
48	sss	27250.00	27749.99	1237.5000	2622.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
49	sss	27750.00	28249.99	1260.0000	2670.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
50	sss	28250.00	28749.99	1282.5000	2717.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
51	sss	28750.00	29249.99	1305.0000	2765.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
52	sss	29250.00	29749.99	1327.5000	2812.5000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
53	sss	29750.00	999999.99	1350.0000	2910.0000	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
54	philhealth	10000.00	100000.00	0.0225	0.0225	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
55	pagibig	0.00	1500.00	0.0100	0.0200	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
56	pagibig	1500.01	999999.99	0.0200	0.0200	2024-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
57	bir	0.00	10416.00	0.0000	0.0000	2018-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
58	bir	10416.01	16666.00	0.0000	0.1500	2018-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
59	bir	16666.01	33332.00	937.5000	0.2000	2018-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
60	bir	33332.01	83332.00	4270.8300	0.2500	2018-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
61	bir	83332.01	333332.00	16770.8300	0.3000	2018-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
62	bir	333332.01	999999.99	91770.8300	0.3500	2018-01-01	t	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: grn_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.grn_items (id, goods_receipt_note_id, purchase_order_item_id, item_id, location_id, quantity_received, quantity_accepted, unit_cost, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: holidays; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.holidays (id, name, date, type, is_recurring, created_at, updated_at) FROM stdin;
1	New Year's Day	2026-01-01	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
2	Chinese New Year	2026-02-17	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
3	EDSA Revolution Anniversary	2026-02-25	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
4	Eid'l Fitr	2026-03-20	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
5	Maundy Thursday	2026-04-02	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
6	Good Friday	2026-04-03	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
7	Black Saturday	2026-04-04	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
8	Araw ng Kagitingan	2026-04-09	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
9	Labor Day	2026-05-01	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
10	Eid'l Adha	2026-05-27	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
11	Independence Day	2026-06-12	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
12	Ninoy Aquino Day	2026-08-21	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
13	National Heroes Day	2026-08-31	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
14	All Saints' Day	2026-11-01	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
15	All Souls' Day	2026-11-02	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
16	Bonifacio Day	2026-11-30	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
17	Feast of Immaculate Conception	2026-12-08	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
18	Christmas Eve	2026-12-24	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
19	Christmas Day	2026-12-25	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
20	Rizal Day	2026-12-30	regular	f	2026-05-03 17:07:02	2026-05-03 17:07:02
21	Last Day of the Year	2026-12-31	special_non_working	f	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: inspection_measurements; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.inspection_measurements (id, inspection_id, inspection_spec_item_id, sample_index, parameter_name, parameter_type, unit_of_measure, nominal_value, tolerance_min, tolerance_max, measured_value, is_critical, is_pass, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inspection_spec_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.inspection_spec_items (id, inspection_spec_id, parameter_name, parameter_type, unit_of_measure, nominal_value, tolerance_min, tolerance_max, is_critical, sort_order, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inspection_specs; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.inspection_specs (id, product_id, version, is_active, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inspections; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.inspections (id, inspection_number, stage, status, product_id, inspection_spec_id, entity_type, entity_id, batch_quantity, sample_size, aql_code, accept_count, reject_count, defect_count, inspector_id, started_at, completed_at, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: invoice_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.invoice_items (id, invoice_id, revenue_account_id, product_id, description, quantity, unit, unit_price, total) FROM stdin;
\.


--
-- Data for Name: invoices; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.invoices (id, invoice_number, customer_id, sales_order_id, delivery_id, date, due_date, is_vatable, subtotal, vat_amount, total_amount, amount_paid, balance, status, journal_entry_id, created_by, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: item_categories; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.item_categories (id, name, parent_id, created_at, updated_at) FROM stdin;
1	Raw Materials	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
2	Packaging	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
3	Finished Goods	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
4	Spare Parts	\N	2026-05-03 17:07:03	2026-05-03 17:07:03
5	Resins	1	2026-05-03 17:07:03	2026-05-03 17:07:03
6	Colorants	1	2026-05-03 17:07:03	2026-05-03 17:07:03
7	Metal Inserts	1	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.items (id, code, name, description, category_id, item_type, unit_of_measure, standard_cost, reorder_method, reorder_point, safety_stock, minimum_order_quantity, lead_time_days, is_critical, is_active, created_at, updated_at, deleted_at) FROM stdin;
1	RM-001	Plastic Resin Type A (ABS)	\N	5	raw_material	kg	120.0000	fixed_quantity	500.000	200.000	25.000	14	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
2	RM-002	Plastic Resin Type B (PP)	\N	5	raw_material	kg	95.0000	fixed_quantity	500.000	200.000	25.000	14	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
3	RM-003	Plastic Resin Type C (PA)	\N	5	raw_material	kg	150.0000	fixed_quantity	300.000	120.000	25.000	14	t	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
4	RM-004	Plastic Resin Type D (POM)	\N	5	raw_material	kg	180.0000	fixed_quantity	300.000	100.000	25.000	14	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
5	RM-010	Black Colorant	\N	6	raw_material	kg	250.0000	fixed_quantity	50.000	20.000	5.000	7	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
6	RM-011	White Colorant	\N	6	raw_material	kg	280.0000	fixed_quantity	50.000	20.000	5.000	7	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
7	RM-012	Gray Colorant	\N	6	raw_material	kg	260.0000	fixed_quantity	40.000	15.000	5.000	7	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
8	RM-050	Small Metal Insert	\N	7	raw_material	pcs	5.5000	fixed_quantity	5000.000	2000.000	1000.000	21	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
9	RM-051	Large Metal Insert	\N	7	raw_material	pcs	8.0000	fixed_quantity	4000.000	1500.000	1000.000	21	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
10	RM-052	Metal Core (Bobbin)	\N	7	raw_material	pcs	12.0000	fixed_quantity	3000.000	1000.000	500.000	21	t	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
11	PKG-001	Standard Poly Bag	\N	2	packaging	pcs	0.5000	fixed_quantity	2000.000	500.000	500.000	5	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
12	PKG-002	Shipping Box (50 pcs)	\N	2	packaging	pcs	15.0000	fixed_quantity	1000.000	300.000	100.000	5	f	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: journal_entries; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.journal_entries (id, entry_number, date, description, reference_type, reference_id, total_debit, total_credit, status, posted_at, posted_by, created_by, created_at, updated_at, reversed_by_entry_id) FROM stdin;
\.


--
-- Data for Name: journal_entry_lines; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.journal_entry_lines (id, journal_entry_id, account_id, line_no, debit, credit, description) FROM stdin;
\.


--
-- Data for Name: leave_requests; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.leave_requests (id, leave_request_no, employee_id, leave_type_id, start_date, end_date, days, reason, document_path, status, dept_approver_id, dept_approved_at, hr_approver_id, hr_approved_at, rejection_reason, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: leave_types; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.leave_types (id, name, code, default_balance, is_paid, requires_document, is_convertible_on_separation, is_convertible_year_end, conversion_rate, is_active, created_at, updated_at) FROM stdin;
1	Vacation Leave	VL	15.0	t	f	t	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
2	Sick Leave	SL	15.0	t	t	f	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
3	Service Incentive Leave	SIL	5.0	t	f	t	t	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
4	Maternity Leave	ML	105.0	t	t	f	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
5	Paternity Leave	PL	7.0	t	t	f	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
6	Solo Parent Leave	SPL	7.0	t	t	f	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
7	VAWC Leave	VAWC	10.0	t	t	f	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
8	Special Leave for Women	SLW	60.0	t	t	f	f	1.00	t	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: loan_payments; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.loan_payments (id, loan_id, payroll_id, amount, payment_date, payment_type, remarks, created_at) FROM stdin;
\.


--
-- Data for Name: machine_downtimes; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.machine_downtimes (id, machine_id, work_order_id, start_time, end_time, duration_minutes, category, description, maintenance_order_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: machines; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.machines (id, machine_code, name, tonnage, machine_type, operators_required, available_hours_per_day, status, current_work_order_id, created_at, updated_at, deleted_at, asset_id) FROM stdin;
1	IMM-01	Toshiba EC100SX	100	injection_molder	1.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	1
2	IMM-02	Toshiba EC100SX	100	injection_molder	1.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	2
3	IMM-03	Sumitomo SE130	130	injection_molder	1.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	3
4	IMM-04	Sumitomo SE130	130	injection_molder	1.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	4
5	IMM-05	Nissei NEX180	180	injection_molder	1.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	5
6	IMM-06	Nissei NEX180	180	injection_molder	1.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	6
7	IMM-07	Fanuc Roboshot	220	injection_molder	1.5	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	7
8	IMM-08	Fanuc Roboshot	220	injection_molder	1.5	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	8
9	IMM-09	JSW J280AD	280	injection_molder	2.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	9
10	IMM-10	JSW J280AD	280	injection_molder	2.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	10
11	IMM-11	Toshiba EC450	450	injection_molder	2.0	16.0	idle	\N	2026-05-03 17:07:03	2026-05-03 17:07:03	\N	11
12	IMM-12	Toshiba EC650	650	injection_molder	2.0	16.0	idle	\N	2026-05-03 17:07:04	2026-05-03 17:07:04	\N	12
\.


--
-- Data for Name: maintenance_logs; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.maintenance_logs (id, work_order_id, description, logged_by, created_at) FROM stdin;
1	1	Auto-seeded sample maintenance log entry.	1	2026-05-03 17:07:05
2	2	Auto-seeded sample maintenance log entry.	1	2026-05-03 17:07:05
3	3	Auto-seeded sample maintenance log entry.	1	2026-05-03 17:07:05
\.


--
-- Data for Name: maintenance_schedules; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.maintenance_schedules (id, maintainable_type, maintainable_id, schedule_type, description, interval_type, interval_value, last_performed_at, next_due_at, is_active, created_at, updated_at, deleted_at) FROM stdin;
1	machine	1	preventive	Quarterly preventive maintenance — IMM-01	days	90	2026-03-04 17:07:05	2026-05-16 17:07:05	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
2	machine	2	preventive	Quarterly preventive maintenance — IMM-02	days	90	2026-02-13 17:07:05	2026-07-19 17:07:05	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
3	machine	3	preventive	Quarterly preventive maintenance — IMM-03	days	90	2026-02-19 17:07:05	2026-05-30 17:07:05	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
4	machine	4	preventive	Quarterly preventive maintenance — IMM-04	days	90	2026-03-31 17:07:05	2026-06-20 17:07:05	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
5	machine	5	preventive	Quarterly preventive maintenance — IMM-05	days	90	2026-04-11 17:07:05	2026-07-17 17:07:05	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
6	machine	6	preventive	Quarterly preventive maintenance — IMM-06	days	90	2026-03-01 17:07:05	2026-06-09 17:07:05	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
7	mold	1	preventive	Shot-count refurbishment — M-WB-001	shots	100000	2026-02-03 17:07:05	\N	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
8	mold	2	preventive	Shot-count refurbishment — M-WB-002	shots	100000	2026-03-03 17:07:05	\N	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
9	mold	3	preventive	Shot-count refurbishment — M-WB-003	shots	80000	2026-03-03 17:07:05	\N	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
10	mold	4	preventive	Shot-count refurbishment — M-PC-001	shots	60000	2026-03-03 17:07:05	\N	t	2026-05-03 17:07:05	2026-05-03 17:07:05	\N
\.


--
-- Data for Name: maintenance_work_orders; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.maintenance_work_orders (id, mwo_number, maintainable_type, maintainable_id, schedule_id, type, priority, description, assigned_to, status, started_at, completed_at, downtime_minutes, cost, remarks, created_by, created_at, updated_at) FROM stdin;
1	MWO-202605-0001	machine	1	1	preventive	medium	Quarterly preventive maintenance — IMM-01	\N	completed	2026-05-02 17:07:05	2026-05-03 20:07:05	180	0.00	\N	1	2026-05-03 17:07:05	2026-05-03 17:07:05
2	MWO-202605-0002	machine	2	2	preventive	medium	Quarterly preventive maintenance — IMM-02	\N	in_progress	2026-05-01 17:07:05	\N	0	0.00	\N	1	2026-05-03 17:07:05	2026-05-03 17:07:05
3	MWO-202605-0003	machine	3	3	preventive	medium	Quarterly preventive maintenance — IMM-03	\N	in_progress	2026-04-30 17:07:05	\N	0	0.00	\N	1	2026-05-03 17:07:05	2026-05-03 17:07:05
4	MWO-202605-0004	machine	1	\N	corrective	high	Hydraulic pressure dropped during 2nd shift; investigate and repair.	\N	open	\N	\N	0	0.00	\N	1	2026-05-03 17:07:05	2026-05-03 17:07:05
\.


--
-- Data for Name: material_issue_slip_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.material_issue_slip_items (id, material_issue_slip_id, item_id, location_id, quantity_issued, unit_cost, total_cost, material_reservation_id, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: material_issue_slips; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.material_issue_slips (id, slip_number, work_order_id, issued_date, issued_by, created_by, status, total_value, reference_text, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: material_reservations; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.material_reservations (id, item_id, work_order_id, location_id, quantity, status, reserved_at, released_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_create_roles_table	1
2	0002_create_permissions_table	1
3	0003_create_role_permissions_table	1
4	0004_create_users_table	1
5	0005_create_password_history_table	1
6	0006_create_sessions_table	1
7	0007_create_document_sequences_table	1
8	0008_create_audit_logs_table	1
9	0009_create_workflow_definitions_table	1
10	0010_create_approval_records_table	1
11	0011_create_notifications_table	1
12	0012_create_notification_preferences_table	1
13	0013_create_settings_table	1
14	0014_create_departments_table	1
15	0015_create_positions_table	1
16	0016_create_employees_table	1
17	0017_alter_departments_add_head_employee_fk	1
18	0018_create_employee_documents_table	1
19	0019_create_employment_history_table	1
20	0020_create_employee_property_table	1
21	0021_create_shifts_table	1
22	0022_create_employee_shift_assignments_table	1
23	0023_create_holidays_table	1
24	0024_create_attendances_table	1
25	0025_create_overtime_requests_table	1
26	0026_create_leave_types_table	1
27	0027_create_employee_leave_balances_table	1
28	0028_create_leave_requests_table	1
29	0029_create_employee_loans_table	1
30	0030_create_loan_payments_table	1
31	0031_create_government_contribution_tables_table	1
32	0032_create_payroll_periods_table	1
33	0033_create_payrolls_table	1
34	0034_create_payroll_deduction_details_table	1
35	0035_create_payroll_adjustments_table	1
36	0036_create_thirteenth_month_accruals_table	1
37	0037_create_bank_file_records_table	1
38	0038_create_accounts_table	1
39	0039_create_journal_entries_table	1
40	0040_create_journal_entry_lines_table	1
41	0041_add_journal_entry_fk_to_payroll_periods	1
42	0042_add_columns_to_journal_entries_table	1
43	0043_create_vendors_table	1
44	0044_create_bills_table	1
45	0045_create_bill_items_table	1
46	0046_create_bill_payments_table	1
47	0047_create_customers_table	1
48	0048_create_invoices_table	1
49	0049_create_invoice_items_table	1
50	0050_create_collections_table	1
51	0051_create_item_categories_table	1
52	0052_create_items_table	1
53	0053_create_warehouses_table	1
54	0054_create_warehouse_zones_table	1
55	0055_create_warehouse_locations_table	1
56	0056_create_stock_levels_table	1
57	0057_create_stock_movements_table	1
58	0058_create_purchase_requests_table	1
59	0059_create_purchase_request_items_table	1
60	0060_create_purchase_orders_table	1
61	0061_create_purchase_order_items_table	1
62	0062_create_approved_suppliers_table	1
63	0063_create_goods_receipt_notes_table	1
64	0064_create_grn_items_table	1
65	0065_create_material_issue_slips_table	1
66	0066_create_material_issue_slip_items_table	1
67	0067_create_material_reservations_table	1
68	0068_add_three_way_match_columns_to_bills	1
69	0069_create_products_table	1
70	0070_create_product_price_agreements_table	1
71	0071_create_sales_orders_table	1
72	0072_create_sales_order_items_table	1
73	0073_create_bill_of_materials_table	1
74	0074_create_bom_items_table	1
75	0075_create_machines_table	1
76	0076_create_molds_table	1
77	0077_create_mold_machine_compatibility_table	1
78	0078_create_mold_history_table	1
79	0079_create_defect_types_table	1
80	0080_create_work_orders_table	1
81	0081_create_work_order_materials_table	1
82	0082_create_work_order_outputs_table	1
83	0083_create_work_order_defects_table	1
84	0084_create_machine_downtimes_table	1
85	0085_create_production_schedules_table	1
86	0086_create_mrp_plans_table	1
87	0087_create_inspection_specs_table	1
88	0088_create_inspection_spec_items_table	1
89	0089_create_inspections_table	1
90	0090_create_inspection_measurements_table	1
91	0091_create_non_conformance_reports_table	1
92	0092_create_ncr_actions_table	1
93	0093_create_shipments_table	1
94	0094_create_shipment_documents_table	1
95	0095_create_vehicles_table	1
96	0096_create_deliveries_table	1
97	0097_create_delivery_items_table	1
98	0098_create_customer_complaints_table	1
99	0099_create_complaint_8d_reports_table	1
100	0100_create_maintenance_schedules_table	1
101	0101_create_maintenance_work_orders_table	1
102	0102_create_maintenance_logs_table	1
103	0103_create_spare_part_usage_table	1
104	0104_create_assets_table	1
105	0105_create_asset_depreciations_table	1
106	0106_add_asset_id_to_vehicles_table	1
107	0107_create_clearances_table	1
108	0108_add_performance_indexes	1
109	0109_add_asset_id_to_machines_table	1
\.


--
-- Data for Name: mold_history; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.mold_history (id, mold_id, event_type, description, cost, performed_by, event_date, shot_count_at_event, created_at) FROM stdin;
1	1	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
2	2	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
3	3	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
4	4	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
5	5	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
6	6	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
7	7	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
8	8	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
9	9	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
10	10	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
11	11	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
12	12	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
13	13	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
14	14	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
15	15	created	Initial seed.	\N	\N	2026-05-03	0	2026-05-03 09:07:04
\.


--
-- Data for Name: mold_machine_compatibility; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.mold_machine_compatibility (mold_id, machine_id) FROM stdin;
1	1
1	2
1	3
1	4
1	5
1	6
1	7
1	8
2	1
2	2
2	3
2	4
2	5
2	6
2	7
2	8
3	1
3	2
3	3
3	4
3	5
3	6
3	7
3	8
4	1
4	2
4	3
4	4
4	5
4	6
4	7
4	8
4	9
4	10
5	1
5	2
5	3
5	4
5	5
5	6
5	7
5	8
5	9
5	10
6	1
6	2
6	3
6	4
6	5
6	6
6	7
6	8
6	9
6	10
7	5
7	6
7	7
7	8
7	9
7	10
7	11
8	5
8	6
8	7
8	8
8	9
8	10
8	11
9	5
9	6
9	7
9	8
9	9
9	10
9	11
10	1
10	2
10	3
10	4
10	5
10	6
11	1
11	2
11	3
11	4
11	5
11	6
12	1
12	2
12	3
12	4
12	5
12	6
12	7
12	8
13	1
13	2
13	3
13	4
13	5
13	6
13	7
13	8
14	1
14	2
14	3
14	4
14	5
14	6
14	7
14	8
15	1
15	2
15	3
15	4
15	5
15	6
15	7
15	8
\.


--
-- Data for Name: molds; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.molds (id, mold_code, name, product_id, cavity_count, cycle_time_seconds, output_rate_per_hour, setup_time_minutes, current_shot_count, max_shots_before_maintenance, lifetime_total_shots, lifetime_max_shots, status, location, asset_id, created_at, updated_at, deleted_at) FROM stdin;
1	M-WB-001	WB-001 4-cav steel mold A	1	4	20	720	90	0	100000	0	500000	available	Tooling Crib · Bay A	13	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
2	M-WB-002	WB-001 4-cav steel mold B	1	4	20	720	90	0	100000	0	500000	available	Tooling Crib · Bay A	14	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
3	M-WB-003	WB-002 4-cav heavy duty	2	4	24	600	90	0	80000	0	400000	available	Tooling Crib · Bay A	15	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
4	M-PC-001	PC-001 8-cav	3	8	30	960	90	0	60000	0	300000	available	Tooling Crib · Bay A	16	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
5	M-PC-002	PC-001 8-cav backup	3	8	30	960	90	0	60000	0	300000	available	Tooling Crib · Bay A	17	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
6	M-PC-003	PC-002 8-cav	4	8	30	960	90	0	60000	0	300000	available	Tooling Crib · Bay A	18	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
7	M-RC-001	RC-001 2-cav	5	2	35	205	90	0	50000	0	250000	available	Tooling Crib · Bay A	19	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
8	M-RC-002	RC-001 2-cav backup	5	2	35	205	90	0	50000	0	250000	available	Tooling Crib · Bay A	20	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
9	M-RC-003	RC-002 2-cav large	8	2	45	160	90	0	40000	0	200000	available	Tooling Crib · Bay A	21	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
10	M-BB-001	BB-001 4-cav bobbin	6	4	18	800	90	0	80000	0	400000	available	Tooling Crib · Bay A	22	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
11	M-BB-002	BB-001 4-cav bobbin backup	6	4	18	800	90	0	80000	0	400000	available	Tooling Crib · Bay A	23	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
12	M-BU-001	BU-001 6-cav	7	6	22	981	90	0	70000	0	350000	available	Tooling Crib · Bay A	24	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
13	M-BU-002	BU-001 6-cav backup	7	6	22	981	90	0	70000	0	350000	available	Tooling Crib · Bay A	25	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
14	M-BU-003	BU-001 6-cav backup 2	7	6	22	981	90	0	70000	0	350000	available	Tooling Crib · Bay A	26	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
15	M-WB-004	WB-002 4-cav backup	2	4	24	600	90	0	80000	0	400000	available	Tooling Crib · Bay A	27	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
\.


--
-- Data for Name: mrp_plans; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.mrp_plans (id, mrp_plan_no, sales_order_id, version, status, generated_by, total_lines, shortages_found, auto_pr_count, draft_wo_count, diagnostics, generated_at, created_at, updated_at) FROM stdin;
1	MRP-202605-0001	1	1	active	1	1	0	0	1	[{"item_id":1,"item_code":"RM-001","gross":1.58,"on_hand":237,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"},{"item_id":5,"item_code":"RM-010","gross":0.2,"on_hand":785,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"},{"item_id":11,"item_code":"PKG-001","gross":100,"on_hand":1607,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"}]	2026-05-03 17:07:04	2026-05-03 17:07:04	2026-05-03 17:07:04
2	MRP-202605-0002	2	1	active	1	1	0	0	1	[{"item_id":1,"item_code":"RM-001","gross":3.15,"on_hand":237,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"},{"item_id":5,"item_code":"RM-010","gross":0.465,"on_hand":785,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"},{"item_id":11,"item_code":"PKG-001","gross":150,"on_hand":1607,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"}]	2026-05-03 17:07:04	2026-05-03 17:07:04	2026-05-03 17:07:04
3	MRP-202605-0003	3	1	active	1	1	0	0	1	[{"item_id":2,"item_code":"RM-002","gross":5.26,"on_hand":374,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"},{"item_id":6,"item_code":"RM-011","gross":0.62,"on_hand":922,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"},{"item_id":11,"item_code":"PKG-001","gross":200,"on_hand":1607,"reserved":0,"in_transit":0,"net":0,"action":"sufficient"}]	2026-05-03 17:07:04	2026-05-03 17:07:04	2026-05-03 17:07:04
\.


--
-- Data for Name: ncr_actions; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.ncr_actions (id, ncr_id, action_type, description, performed_by, performed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: non_conformance_reports; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.non_conformance_reports (id, ncr_number, source, severity, status, product_id, inspection_id, complaint_id, defect_description, affected_quantity, disposition, root_cause, corrective_action, created_by, assigned_to, closed_by, closed_at, replacement_work_order_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notification_preferences; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.notification_preferences (id, user_id, notification_type, channel, enabled, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at) FROM stdin;
d1bcefce-1c96-44ca-b84f-0ccde1c98b80	leave.submitted	App\\Modules\\Auth\\Models\\User	1	{"message":"Leave request LR-202604-0010 awaiting your approval."}	\N	2026-05-03 16:07:05	2026-05-03 16:07:05
fdbd9d4a-8b36-4bad-92e6-0d19436ae483	pr.urgent	App\\Modules\\Auth\\Models\\User	1	{"message":"Urgent PR PR-202604-0008 due to low spare-part stock."}	2026-05-03 15:07:05	2026-05-03 15:07:05	2026-05-03 15:07:05
3909915e-b8a5-45e2-8559-5ecf584a3bd9	wo.completed	App\\Modules\\Auth\\Models\\User	1	{"message":"Work order WO-202604-0006 completed (10,000 good \\/ 45 reject)."}	2026-05-03 14:07:05	2026-05-03 14:07:05	2026-05-03 14:07:05
213746ad-a01b-4f5c-8386-ad90bf1f5072	machine.breakdown	App\\Modules\\Auth\\Models\\User	1	{"message":"IMM-04 entered breakdown. WO paused."}	\N	2026-05-03 13:07:05	2026-05-03 13:07:05
e382a628-f12f-4669-9214-0a918e997867	maintenance.assigned	App\\Modules\\Auth\\Models\\User	1	{"message":"You were assigned MWO-202604-0001."}	2026-05-03 12:07:05	2026-05-03 12:07:05	2026-05-03 12:07:05
b89c876e-6271-4ae6-9980-e52134f967f2	ncr.opened	App\\Modules\\Auth\\Models\\User	1	{"message":"NCR-202604-0003 opened from outgoing inspection failure."}	2026-05-03 11:07:05	2026-05-03 11:07:05	2026-05-03 11:07:05
7aa7e720-6061-4ca3-b84c-e7215f18b832	mold.shot_limit	App\\Modules\\Auth\\Models\\User	1	{"message":"Mold MOLD-08 reached 82% of shot threshold."}	\N	2026-05-03 10:07:05	2026-05-03 10:07:05
29b214ea-cc89-4ee4-9e34-29baeb431ad5	payroll.finalized	App\\Modules\\Auth\\Models\\User	1	{"message":"Payroll period 2026-04 first half finalized."}	2026-05-03 09:07:05	2026-05-03 09:07:05	2026-05-03 09:07:05
\.


--
-- Data for Name: overtime_requests; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.overtime_requests (id, employee_id, date, hours_requested, reason, status, approved_by, approved_at, rejection_reason, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: password_history; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.password_history (id, user_id, password_hash, created_at) FROM stdin;
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: payroll_adjustments; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.payroll_adjustments (id, payroll_period_id, employee_id, original_payroll_id, type, amount, reason, approved_by, status, applied_at, applied_to_payroll_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: payroll_deduction_details; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.payroll_deduction_details (id, payroll_id, deduction_type, description, amount, reference_id) FROM stdin;
\.


--
-- Data for Name: payroll_periods; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.payroll_periods (id, period_start, period_end, payroll_date, is_first_half, is_thirteenth_month, status, journal_entry_id, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: payrolls; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.payrolls (id, payroll_period_id, employee_id, pay_type, days_worked, basic_pay, overtime_pay, night_diff_pay, holiday_pay, gross_pay, sss_ee, sss_er, philhealth_ee, philhealth_er, pagibig_ee, pagibig_er, withholding_tax, loan_deductions, other_deductions, adjustment_amount, total_deductions, net_pay, error_message, computed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.permissions (id, name, slug, module, description, created_at, updated_at) FROM stdin;
1	Manage Roles & Permissions	admin.roles.manage	admin	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
2	Manage System Settings	admin.settings.manage	admin	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
3	View Audit Logs	admin.audit_logs.view	admin	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
4	Manage Users	admin.users.manage	admin	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
5	Manage Government Contribution Tables	admin.gov_tables.manage	admin	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
6	View Departments	hr.departments.view	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
7	Manage Departments	hr.departments.manage	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
8	View Positions	hr.positions.view	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
9	Manage Positions	hr.positions.manage	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
10	View Employees	hr.employees.view	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
11	Create Employees	hr.employees.create	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
12	Edit Employees	hr.employees.edit	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
13	Delete Employees	hr.employees.delete	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
14	Export Employees	hr.employees.export	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
15	View Sensitive Employee Data (SSS, TIN, Bank)	hr.employees.view_sensitive	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
16	Initiate Employee Separation	hr.employees.separate	hr	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
17	View Attendance	attendance.view	attendance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
18	Import Attendance (CSV)	attendance.import	attendance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
19	Edit Attendance	attendance.edit	attendance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
20	Manage Shifts	attendance.shifts.manage	attendance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
21	Manage Holidays	attendance.holidays.manage	attendance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
22	Approve Overtime	attendance.ot.approve	attendance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
23	View Leave Requests	leave.view	leave	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
24	Create Leave Request	leave.create	leave	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
25	Approve Leave (Dept Head)	leave.approve_dept	leave	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
26	Approve Leave (HR)	leave.approve_hr	leave	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
27	Manage Leave Types	leave.types.manage	leave	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
28	View Payroll	payroll.view	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
29	Create Payroll Period	payroll.periods.create	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
30	Compute Payroll	payroll.periods.compute	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
31	Approve Payroll	payroll.periods.approve	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
32	Finalize Payroll	payroll.periods.finalize	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
33	Create Payroll Adjustment	payroll.adjustments.create	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
34	View Any Payslip	payroll.payslip.view_all	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
35	Run 13th Month Pay	payroll.thirteenth_month.run	payroll	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
36	View Loans	loans.view	loans	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
37	Create Loan / Cash Advance	loans.create	loans	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
38	Approve Loan	loans.approve	loans	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
39	Write Off Loan	loans.write_off	loans	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
40	View Accounting	accounting.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
41	View Finance Dashboard	accounting.dashboard.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
42	View Chart of Accounts	accounting.coa.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
43	Manage Chart of Accounts	accounting.coa.manage	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
44	Deactivate Accounts	accounting.coa.deactivate	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
45	View Journal Entries	accounting.journal.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
46	Create Journal Entries	accounting.journal.create	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
47	Post Journal Entries	accounting.journal.post	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
48	Reverse Posted Journal Entries	accounting.journal.reverse	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
49	View Vendors	accounting.vendors.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
50	Manage Vendors	accounting.vendors.manage	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
51	View Bills	accounting.bills.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
52	Create Bills	accounting.bills.create	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
53	Update / Cancel Bills	accounting.bills.update	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
54	Pay Bills	accounting.bills.pay	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
55	View Customers	accounting.customers.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
56	Manage Customers	accounting.customers.manage	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
57	View Invoices	accounting.invoices.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
58	Create Invoices	accounting.invoices.create	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
59	Update / Cancel Invoices	accounting.invoices.update	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
60	Record Collections	accounting.invoices.collect	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
61	View Financial Statements	accounting.statements.view	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
62	Export Statements (CSV/PDF)	accounting.statements.export	accounting	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
63	View Inventory	inventory.view	inventory	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
64	Manage Items	inventory.items.manage	inventory	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
65	Manage Warehouse Structure	inventory.warehouse.manage	inventory	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
66	Create / Accept GRN	inventory.grn.create	inventory	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
67	Issue Materials	inventory.issue.create	inventory	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
68	Adjust / Transfer Stock	inventory.adjust	inventory	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
69	View Purchasing	purchasing.view	purchasing	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
70	Create Purchase Request	purchasing.pr.create	purchasing	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
71	Approve Purchase Request	purchasing.pr.approve	purchasing	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
72	Create Purchase Order	purchasing.po.create	purchasing	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
73	Approve Purchase Order	purchasing.po.approve	purchasing	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
74	Send PO to Supplier	purchasing.po.send	purchasing	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
75	View Supply Chain	supply_chain.view	supply_chain	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
76	Manage Shipments	supply_chain.shipments.manage	supply_chain	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
77	Manage Vehicles	supply_chain.fleet.manage	supply_chain	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
78	Create Deliveries	supply_chain.deliveries.create	supply_chain	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
79	Confirm Customer Delivery	supply_chain.deliveries.confirm	supply_chain	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
80	View Production	production.view	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
81	Create Work Order	production.wo.create	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
82	Confirm Work Order	production.wo.confirm	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
83	Record Production Output	production.wo.record	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
84	View Work Orders	production.work_orders.view	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
85	Transition Work Order Status	production.work_orders.lifecycle	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
86	Manage Machines	production.machines.manage	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
87	Transition Machine Status	production.machines.transition	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
88	Manage Molds	production.molds.manage	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
89	View Production Schedule	production.schedule.view	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
90	Confirm Production Schedule	production.schedule.confirm	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
91	View Production Dashboard	production.dashboard.view	production	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
92	View MRP	mrp.view	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
93	Schedule Production	mrp.schedule	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
94	View Bills of Materials	mrp.boms.view	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
95	Manage Bills of Materials	mrp.boms.manage	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
96	View Machines	mrp.machines.view	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
97	View Molds	mrp.molds.view	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
98	View MRP Plans	mrp.plans.view	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
99	Re-run MRP Plan	mrp.plans.run	mrp	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
100	View CRM	crm.view	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
101	Manage Customers	crm.customers.manage	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
102	View Products	crm.products.view	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
103	Manage Products	crm.products.manage	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
104	View Price Agreements	crm.price_agreements.view	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
105	Manage Price Agreements	crm.price_agreements.manage	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
106	View Sales Orders	crm.sales_orders.view	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
107	Create Sales Orders	crm.sales_orders.create	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
108	Update Draft Sales Orders	crm.sales_orders.update	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
109	Delete Draft Sales Orders	crm.sales_orders.delete	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
110	Confirm Sales Orders	crm.sales_orders.confirm	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
111	Cancel Sales Orders	crm.sales_orders.cancel	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
112	Create Sales Orders (legacy)	crm.so.create	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
113	Manage Complaints	crm.complaints.manage	crm	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
114	View Quality	quality.view	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
115	Create Inspections	quality.inspections.create	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
116	Edit Inspections	quality.inspections.edit	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
117	View Inspections	quality.inspections.view	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
118	Manage Inspections	quality.inspections.manage	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
119	View Inspection Specs	quality.specs.view	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
120	Manage Inspection Specs	quality.specs.manage	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
121	View NCRs	quality.ncr.view	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
122	Manage NCRs	quality.ncr.manage	quality	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
123	View Maintenance	maintenance.view	maintenance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
124	Create Maintenance Work Order	maintenance.wo.create	maintenance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
125	Assign Maintenance Work Order	maintenance.wo.assign	maintenance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
126	Complete Maintenance Work Order	maintenance.wo.complete	maintenance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
127	Manage Maintenance Schedules	maintenance.schedules.manage	maintenance	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
128	View Assets	assets.view	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
129	Create Asset	assets.create	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
130	Update Asset	assets.update	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
131	Delete Asset	assets.delete	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
132	Dispose Asset	assets.dispose	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
133	View Asset Depreciation	assets.depreciation.view	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
134	Run Asset Depreciation	assets.depreciation.run	assets	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
135	View Employee Separations	hr.separation.view	hr_separation	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
136	Initiate Employee Separation	hr.separation.initiate	hr_separation	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
137	Sign Clearance Item	hr.clearance.sign	hr_separation	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
138	Finalize Separation & Final Pay	hr.separation.finalize	hr_separation	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
139	View Plant Manager Dashboard	dashboard.plant_manager.view	dashboards	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
140	View HR Dashboard	dashboard.hr.view	dashboards	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
141	View PPC Dashboard	dashboard.ppc.view	dashboards	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
142	View Accounting Dashboard	dashboard.accounting.view	dashboards	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
143	Use Global Search	search.global	platform	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
144	Manage Own Notification Preferences	notifications.preferences.manage	platform	\N	2026-05-03 17:07:01	2026-05-03 17:07:01
\.


--
-- Data for Name: positions; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.positions (id, title, department_id, salary_grade, created_at, updated_at) FROM stdin;
1	Chairman	1	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
2	President	1	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
3	Vice President	1	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
4	HR Manager	2	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
5	Gen Admin Officer	2	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
6	HR Staff	2	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
7	Accounting Officer	3	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
8	Accounting Staff	3	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
9	Plant Manager	4	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
10	Production Manager	4	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
11	Production Head	4	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
12	Processing Head	4	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
13	Production Operator	4	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
14	QC/QA Manager	5	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
15	QC/QA Head	5	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
16	QC Inspector	5	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
17	Management System Head	5	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
18	Warehouse Head	6	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
19	Warehouse Staff	6	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
20	Driver	6	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
21	Purchasing Officer	7	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
22	Purchasing Staff	7	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
23	PPC Head	8	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
24	PPC Staff	8	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
25	Maintenance Head	9	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
26	Maintenance Technician	9	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
27	Mold Manager	10	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
28	Mold Technician	10	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
29	ImpEx Officer	11	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
30	ImpEx Staff	11	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
31	Admin Staff	12	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: product_price_agreements; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.product_price_agreements (id, product_id, customer_id, price, effective_from, effective_to, created_at, updated_at) FROM stdin;
1	1	1	22.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
2	2	1	30.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
3	3	1	35.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
4	7	1	26.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
5	1	2	23.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
6	4	2	35.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
7	5	2	47.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
8	2	3	31.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
9	3	3	35.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
10	8	3	70.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
11	6	4	28.00	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
12	7	4	26.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
13	6	5	28.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
14	1	5	23.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
15	5	5	47.50	2026-01-01	2026-12-31	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: production_schedules; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.production_schedules (id, work_order_id, machine_id, mold_id, scheduled_start, scheduled_end, priority_order, status, is_confirmed, confirmed_by, confirmed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: products; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.products (id, part_number, name, description, unit_of_measure, standard_cost, is_active, created_at, updated_at, deleted_at) FROM stdin;
1	WB-001	Wiper Bushing (Standard)	\N	pcs	18.50	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
2	WB-002	Wiper Bushing (Heavy Duty)	\N	pcs	24.50	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
3	PC-001	Pivot Cap Cover Type A	\N	pcs	28.00	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
4	PC-002	Pivot Cap Cover Type B	\N	pcs	28.50	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
5	RC-001	Relay Cover Standard	\N	pcs	38.00	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
6	BB-001	Wiper Motor Bobbin	\N	pcs	22.00	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
7	BU-001	Windshield Wiper Bushing	\N	pcs	21.00	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
8	RC-002	Relay Cover Large	\N	pcs	56.00	t	2026-05-03 17:07:03	2026-05-03 17:07:03	\N
\.


--
-- Data for Name: purchase_order_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.purchase_order_items (id, purchase_order_id, item_id, purchase_request_item_id, description, quantity, unit, unit_price, total, quantity_received, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: purchase_orders; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.purchase_orders (id, po_number, vendor_id, purchase_request_id, date, expected_delivery_date, subtotal, vat_amount, total_amount, is_vatable, status, requires_vp_approval, current_approval_step, approved_by, approved_at, sent_to_supplier_at, created_by, remarks, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: purchase_request_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.purchase_request_items (id, purchase_request_id, item_id, description, quantity, unit, estimated_unit_price, purpose, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: purchase_requests; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.purchase_requests (id, pr_number, requested_by, department_id, date, reason, priority, status, is_auto_generated, current_approval_step, submitted_at, approved_at, created_at, updated_at, mrp_plan_id) FROM stdin;
\.


--
-- Data for Name: role_permissions; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.role_permissions (role_id, permission_id) FROM stdin;
1	1
1	2
1	3
1	4
1	5
1	6
1	7
1	8
1	9
1	10
1	11
1	12
1	13
1	14
1	15
1	16
1	17
1	18
1	19
1	20
1	21
1	22
1	23
1	24
1	25
1	26
1	27
1	28
1	29
1	30
1	31
1	32
1	33
1	34
1	35
1	36
1	37
1	38
1	39
1	40
1	41
1	42
1	43
1	44
1	45
1	46
1	47
1	48
1	49
1	50
1	51
1	52
1	53
1	54
1	55
1	56
1	57
1	58
1	59
1	60
1	61
1	62
1	63
1	64
1	65
1	66
1	67
1	68
1	69
1	70
1	71
1	72
1	73
1	74
1	75
1	76
1	77
1	78
1	79
1	80
1	81
1	82
1	83
1	84
1	85
1	86
1	87
1	88
1	89
1	90
1	91
1	92
1	93
1	94
1	95
1	96
1	97
1	98
1	99
1	100
1	101
1	102
1	103
1	104
1	105
1	106
1	107
1	108
1	109
1	110
1	111
1	112
1	113
1	114
1	115
1	116
1	117
1	118
1	119
1	120
1	121
1	122
1	123
1	124
1	125
1	126
1	127
1	128
1	129
1	130
1	131
1	132
1	133
1	134
1	135
1	136
1	137
1	138
1	139
1	140
1	141
1	142
1	143
1	144
2	6
2	7
2	8
2	9
2	10
2	11
2	12
2	13
2	14
2	15
2	16
2	17
2	18
2	19
2	20
2	21
2	22
2	23
2	24
2	25
2	26
2	27
2	135
2	136
2	137
2	138
2	28
2	34
2	29
2	30
2	31
2	33
2	35
2	140
2	143
2	144
3	28
3	29
3	30
3	31
3	32
3	33
3	34
3	35
3	40
3	41
3	42
3	43
3	44
3	45
3	46
3	47
3	48
3	49
3	50
3	51
3	52
3	53
3	54
3	55
3	56
3	57
3	58
3	59
3	60
3	61
3	62
3	36
3	37
3	38
3	39
3	128
3	129
3	130
3	131
3	132
3	133
3	134
3	5
3	142
3	143
3	144
4	80
4	81
4	82
4	83
4	84
4	85
4	86
4	87
4	88
4	89
4	90
4	91
4	92
4	93
4	63
4	114
4	139
4	141
4	123
4	128
4	143
4	144
5	92
5	93
5	94
5	95
5	96
5	97
5	98
5	99
5	80
5	81
5	82
5	141
5	123
5	128
5	143
5	144
6	69
6	70
6	71
6	72
6	73
6	74
6	63
6	66
6	76
6	49
6	51
7	63
7	64
7	65
7	66
7	67
7	68
8	114
8	115
8	116
8	117
8	118
8	119
8	120
8	121
8	122
9	123
9	124
9	126
9	128
9	143
9	144
10	75
10	76
10	69
11	10
11	17
11	22
11	23
11	25
11	69
11	71
11	137
11	143
11	144
12	23
12	24
12	28
12	144
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.roles (id, name, slug, description, created_at, updated_at) FROM stdin;
1	System Administrator	system_admin	Full access to every module. Override gate via AuthServiceProvider.	2026-05-03 17:07:01	2026-05-03 17:07:01
2	HR Officer	hr_officer	Manages employees, attendance, leave; sees sensitive HR data.	2026-05-03 17:07:01	2026-05-03 17:07:01
3	Finance Officer	finance_officer	Manages payroll finalization, accounting, vendor & customer ledgers.	2026-05-03 17:07:01	2026-05-03 17:07:01
4	Production Manager	production_manager	Oversees work orders, output, OEE.	2026-05-03 17:07:01	2026-05-03 17:07:01
5	PPC Head	ppc_head	Production Planning & Control — owns the schedule and BOMs.	2026-05-03 17:07:01	2026-05-03 17:07:01
6	Purchasing Officer	purchasing_officer	Manages PRs, POs, vendor relationships.	2026-05-03 17:07:01	2026-05-03 17:07:01
7	Warehouse Staff	warehouse_staff	Receives goods, issues materials, counts stock.	2026-05-03 17:07:01	2026-05-03 17:07:01
8	QC Inspector	qc_inspector	Logs inspection results, raises NCRs.	2026-05-03 17:07:01	2026-05-03 17:07:01
9	Maintenance Technician	maintenance_tech	Executes maintenance work orders.	2026-05-03 17:07:01	2026-05-03 17:07:01
10	ImpEx Officer	impex_officer	Tracks imported shipments and customs documents.	2026-05-03 17:07:01	2026-05-03 17:07:01
11	Department Head	department_head	Approves leaves, OT, PRs for their department.	2026-05-03 17:07:01	2026-05-03 17:07:01
12	Employee	employee	Self-service portal access only.	2026-05-03 17:07:01	2026-05-03 17:07:01
\.


--
-- Data for Name: sales_order_items; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.sales_order_items (id, sales_order_id, product_id, quantity, unit_price, total, quantity_delivered, delivery_date) FROM stdin;
1	1	1	100.00	22.50	2250.00	0.00	2026-05-10
2	2	2	150.00	30.00	4500.00	0.00	2026-05-13
3	3	3	200.00	35.00	7000.00	0.00	2026-05-16
4	4	7	250.00	26.00	6500.00	0.00	2026-05-19
5	5	1	300.00	23.00	6900.00	0.00	2026-05-22
\.


--
-- Data for Name: sales_orders; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.sales_orders (id, so_number, customer_id, date, subtotal, vat_amount, total_amount, status, payment_terms_days, delivery_terms, notes, mrp_plan_id, created_by, created_at, updated_at) FROM stdin;
1	SO-202605-0001	1	2026-05-03	2250.00	270.00	2520.00	confirmed	30	Ex-Works (Cavite)	Demo seed — order #1	1	1	2026-05-03 17:07:04	2026-05-03 17:07:04
2	SO-202605-0002	1	2026-05-01	4500.00	540.00	5040.00	confirmed	30	Ex-Works (Cavite)	Demo seed — order #2	2	1	2026-05-03 17:07:04	2026-05-03 17:07:04
3	SO-202605-0003	1	2026-04-29	7000.00	840.00	7840.00	confirmed	30	Ex-Works (Cavite)	Demo seed — order #3	3	1	2026-05-03 17:07:04	2026-05-03 17:07:04
4	SO-202605-0004	1	2026-04-27	6500.00	780.00	7280.00	draft	30	Ex-Works (Cavite)	Demo seed — order #4	\N	1	2026-05-03 17:07:04	2026-05-03 17:07:04
5	SO-202605-0005	2	2026-04-25	6900.00	828.00	7728.00	draft	30	Ex-Works (Cavite)	Demo seed — order #5	\N	1	2026-05-03 17:07:04	2026-05-03 17:07:04
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
gepQgCjAK415HZg09c3NtN8PpxxWrU8Vh2ohIDSu	\N	172.18.0.1	Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:149.0) Gecko/20100101 Firefox/149.0	YToyOntzOjY6Il90b2tlbiI7czo0MDoiTmw3TlZKWGpNOVR4bUk3b2pRN1p3WHh0dzdZRFBsS2NaV1dja2ZjTyI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1777799296
yRtnf602VFv48mrYDgeeXYomMZBxWrzZnJhSeA0k	1	172.18.0.1	Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:149.0) Gecko/20100101 Firefox/149.0	YTo1OntzOjY6Il90b2tlbiI7czo0MDoiWk5UdUVyS25NY1JUWVh0WFZZZGlkeWoyQ241Y21ON3RzQUxvclo1TCI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzY6Imh0dHA6Ly9sb2NhbGhvc3Qvc2FuY3R1bS9jc3JmLWNvb2tpZSI7fXM6NTA6ImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjtpOjE7czoxNzoicGFzc3dvcmRfaGFzaF93ZWIiO3M6NjA6IiQyeSQxMiRpdTd0Q2EuOVhUQ01YUW5BUFFVbHVPOFVjakdnbDF4ajBVV245OUFIQURpa0pscThqM1dLcSI7fQ==	1777799823
1x9mbDhFfEt8PgmCPuifbds02Bel29QtzvCxdyOG	\N	172.18.0.1	Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:149.0) Gecko/20100101 Firefox/149.0	YToyOntzOjY6Il90b2tlbiI7czo0MDoibUo1bXhTdnJEbXh2ZVdhVjVkWjZDVmN0SlBmamZZT0NkQm1iMXFFeSI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1777801751
\.


--
-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.settings (id, key, value, "group", created_at, updated_at) FROM stdin;
1	company.name	"Philippine Ogami Corporation"	company	2026-05-03 17:07:02	2026-05-03 17:07:02
2	company.address	"FCIE Special Economic Zone, Dasmari\\u00f1as, Cavite, Philippines"	company	2026-05-03 17:07:02	2026-05-03 17:07:02
3	company.tin	"000-000-000-000"	company	2026-05-03 17:07:02	2026-05-03 17:07:02
4	fiscal.year_start_month	1	fiscal	2026-05-03 17:07:02	2026-05-03 17:07:02
5	payroll.schedule	"semi_monthly"	payroll	2026-05-03 17:07:02	2026-05-03 17:07:02
6	payroll.cutoff.first_half	15	payroll	2026-05-03 17:07:02	2026-05-03 17:07:02
7	payroll.cutoff.second_half	31	payroll	2026-05-03 17:07:02	2026-05-03 17:07:02
8	approval.po.vp_threshold	50000	approval	2026-05-03 17:07:02	2026-05-03 17:07:02
9	purchasing.three_way_tolerance_qty_pct	5	purchasing	2026-05-03 17:07:02	2026-05-03 17:07:02
10	purchasing.three_way_tolerance_price_pct	5	purchasing	2026-05-03 17:07:02	2026-05-03 17:07:02
11	inventory.allow_negative	false	inventory	2026-05-03 17:07:02	2026-05-03 17:07:02
12	modules.hr	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
13	modules.attendance	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
14	modules.leave	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
15	modules.payroll	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
16	modules.loans	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
17	modules.accounting	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
18	modules.inventory	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
19	modules.purchasing	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
20	modules.crm	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
21	modules.mrp	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
22	modules.production	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
23	modules.supply_chain	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
24	modules.quality	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
25	modules.maintenance	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
26	modules.assets	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
27	modules.search	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
28	modules.notifications	true	modules	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: shifts; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.shifts (id, name, start_time, end_time, break_minutes, is_night_shift, is_extended, auto_ot_hours, is_active, created_at, updated_at) FROM stdin;
1	Day Shift	06:00:00	14:00:00	30	f	f	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
2	Extended Day	06:00:00	18:00:00	30	f	t	4.0	t	2026-05-03 17:07:02	2026-05-03 17:07:02
3	Night Shift	18:00:00	06:00:00	30	t	f	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
4	Office Hours	08:00:00	17:00:00	60	f	f	\N	t	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Data for Name: shipment_documents; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.shipment_documents (id, shipment_id, document_type, file_path, original_filename, file_size_bytes, mime_type, notes, uploaded_by, uploaded_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: shipments; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.shipments (id, shipment_number, purchase_order_id, status, carrier, vessel, container_number, bl_number, etd, atd, eta, ata, customs_clearance_date, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: spare_part_usage; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.spare_part_usage (id, work_order_id, item_id, quantity, unit_cost, total_cost, stock_movement_id, created_at) FROM stdin;
\.


--
-- Data for Name: stock_levels; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.stock_levels (id, item_id, location_id, quantity, reserved_quantity, weighted_avg_cost, last_counted_at, lock_version, updated_at, created_at) FROM stdin;
1	1	1	237.000	0.000	120.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
2	2	2	374.000	0.000	95.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
3	3	3	511.000	0.000	150.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
4	4	4	648.000	0.000	180.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
5	5	5	785.000	0.000	250.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
6	6	6	922.000	0.000	280.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
7	7	7	1059.000	0.000	260.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
8	8	8	1196.000	0.000	5.5000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
9	9	9	1333.000	0.000	8.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
10	10	10	1470.000	0.000	12.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
11	11	11	1607.000	0.000	0.5000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
12	12	12	1744.000	0.000	15.0000	\N	0	2026-05-03 09:07:05	2026-05-03 09:07:05
\.


--
-- Data for Name: stock_movements; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.stock_movements (id, item_id, from_location_id, to_location_id, movement_type, quantity, unit_cost, total_cost, reference_type, reference_id, remarks, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: thirteenth_month_accruals; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.thirteenth_month_accruals (id, employee_id, year, total_basic_earned, accrued_amount, is_paid, paid_date, payroll_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.users (id, name, email, password, role_id, employee_id, is_active, must_change_password, last_activity, password_changed_at, failed_login_attempts, locked_until, theme_mode, sidebar_collapsed, remember_token, created_at, updated_at, deleted_at) FROM stdin;
1	System Administrator	admin@ogami.test	$2y$12$iu7tCa.9XTCMXQnAPQUluO8UcjGgl1xj0UWn99AHADikJlq8j3WKq	1	\N	t	f	2026-05-03 17:17:03	2026-05-03 17:07:02	0	\N	system	f	\N	2026-05-03 17:07:02	2026-05-03 17:17:03	\N
\.


--
-- Data for Name: vehicles; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.vehicles (id, plate_number, name, vehicle_type, capacity_kg, status, notes, created_at, updated_at, asset_id) FROM stdin;
1	TRK-001	Truck 1	truck	5000.00	available	\N	2026-05-03 17:07:04	2026-05-03 17:07:04	28
2	TRK-002	Truck 2	truck	5000.00	available	\N	2026-05-03 17:07:04	2026-05-03 17:07:04	29
3	VAN-001	L300 Van	van	1500.00	available	\N	2026-05-03 17:07:04	2026-05-03 17:07:04	30
\.


--
-- Data for Name: vendors; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.vendors (id, name, contact_person, email, phone, address, tin, payment_terms_days, is_active, created_at, updated_at, deleted_at) FROM stdin;
1	Megaplast Industries Corp.	Account Manager	sales@megaplast.ph	+632-8123-4567	Metro Manila, Philippines	eyJpdiI6IkhJYkdsdXBkbWpXbll1VHFWUGJ3eEE9PSIsInZhbHVlIjoiSU9zc0xxVi9xUy9jYkRiVzEvUjJFdz09IiwibWFjIjoiZTg1OWUxY2U2ZGRlYjk4ZWI1MzAyNmQ0YTY1NDJmZjgxNTEwZGM5NzBjZGM1MzA0NTA3ZDVkZjMyYWM1MTMyZiIsInRhZyI6IiJ9	30	t	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
2	Asia Pacific Polymers, Inc.	Account Manager	orders@apolymers.ph	+632-8222-3344	Metro Manila, Philippines	eyJpdiI6IlcyUFUyeTlpTi9xS01wcUFLTGQyNWc9PSIsInZhbHVlIjoia2VPUFFBNGk4NzNpbmVkS3cvUDJMUT09IiwibWFjIjoiYmZkNmZlNzQ5YjZlNGVkMGJkMGRhMTlmY2YyZjIyYWQwZmE5NWU3NjZlYjQ2NjljNjIyYmI4YTY1NDNiYzM3OCIsInRhZyI6IiJ9	45	t	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
3	Tooling Pro Manufacturing	Account Manager	hello@toolingpro.ph	+632-8345-1122	Metro Manila, Philippines	eyJpdiI6Ii9ZWG81SHJZTHczM2VmNjFHQXBnTVE9PSIsInZhbHVlIjoiK1cvUjQyTkNBY0kwQXYweWhWWUFYQT09IiwibWFjIjoiZDBiYmZhMDhjNzEyNTFhYWQ5OGI2Y2VjMTUzOWNiNzA2ZjYyMTUwZTlmNGEwNWFmMmM3ODE1MmE5NzQ5OThhNyIsInRhZyI6IiJ9	30	t	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
4	Pacific Logistics Solutions	Account Manager	support@paclogistics.ph	+632-8456-2244	Metro Manila, Philippines	eyJpdiI6ImlLQzZsVVhKMFZRaExWQzBjZ1dBa1E9PSIsInZhbHVlIjoiS0tHNUVkM0V6UTM0Ym5PYktjTnNrZz09IiwibWFjIjoiODU3MThlYzA1MTBiMTYyYWRkNjgzYjc0ODMxM2E2NTVlYWI4OTIwNjY1MDZjMjY0YTQ3M2E4MDQ1ZjIzNjcwMSIsInRhZyI6IiJ9	60	t	2026-05-03 17:07:04	2026-05-03 17:07:04	\N
\.


--
-- Data for Name: warehouse_locations; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.warehouse_locations (id, zone_id, code, rack, bin, is_active, created_at, updated_at) FROM stdin;
1	1	A-01	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
2	1	A-02	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
3	1	A-03	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
4	1	A-04	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
5	1	A-05	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
6	1	A-06	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
7	1	A-07	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
8	1	A-08	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
9	1	A-09	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
10	1	A-10	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
11	1	A-11	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
12	1	A-12	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
13	1	A-13	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
14	1	A-14	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
15	1	A-15	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
16	1	A-16	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
17	1	A-17	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
18	1	A-18	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
19	1	A-19	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
20	1	A-20	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
21	2	B-01	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
22	2	B-02	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
23	2	B-03	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
24	2	B-04	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
25	2	B-05	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
26	2	B-06	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
27	2	B-07	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
28	2	B-08	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
29	3	C-01	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
30	3	C-02	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
31	3	C-03	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
32	3	C-04	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
33	3	C-05	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
34	3	C-06	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
35	3	C-07	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
36	3	C-08	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
37	3	C-09	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
38	3	C-10	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
39	3	C-11	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
40	3	C-12	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
41	3	C-13	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
42	3	C-14	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
43	3	C-15	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
44	3	C-16	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
45	3	C-17	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
46	3	C-18	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
47	3	C-19	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
48	3	C-20	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
49	3	C-21	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
50	3	C-22	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
51	3	C-23	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
52	3	C-24	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
53	3	C-25	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
54	3	C-26	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
55	3	C-27	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
56	3	C-28	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
57	3	C-29	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
58	3	C-30	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
59	4	D-01	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
60	4	D-02	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
61	4	D-03	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
62	4	D-04	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
63	4	D-05	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
64	4	D-06	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
65	4	D-07	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
66	4	D-08	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
67	4	D-09	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
68	4	D-10	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
69	5	Q-01	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
70	5	Q-02	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
71	5	Q-03	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
72	5	Q-04	\N	\N	t	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: warehouse_zones; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.warehouse_zones (id, warehouse_id, name, code, zone_type, created_at, updated_at) FROM stdin;
1	1	Zone A — Raw Materials	A	raw_materials	2026-05-03 17:07:03	2026-05-03 17:07:03
2	1	Zone B — Staging	B	staging	2026-05-03 17:07:03	2026-05-03 17:07:03
3	1	Zone C — Finished Goods	C	finished_goods	2026-05-03 17:07:03	2026-05-03 17:07:03
4	1	Zone D — Spare Parts	D	spare_parts	2026-05-03 17:07:03	2026-05-03 17:07:03
5	1	Zone Q — Quarantine	Q	quarantine	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: warehouses; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.warehouses (id, name, code, address, is_active, created_at, updated_at) FROM stdin;
1	Main Warehouse	MW	FCIE Special Economic Zone, Dasmariñas, Cavite	t	2026-05-03 17:07:03	2026-05-03 17:07:03
\.


--
-- Data for Name: work_order_defects; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.work_order_defects (id, output_id, defect_type_id, count) FROM stdin;
\.


--
-- Data for Name: work_order_materials; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.work_order_materials (id, work_order_id, item_id, bom_quantity, actual_quantity_issued, variance) FROM stdin;
1	1	1	1.580	0.000	0.000
2	1	5	0.200	0.000	0.000
3	1	11	100.000	0.000	0.000
4	2	1	3.150	0.000	0.000
5	2	5	0.465	0.000	0.000
6	2	11	150.000	0.000	0.000
7	3	2	5.260	0.000	0.000
8	3	6	0.620	0.000	0.000
9	3	11	200.000	0.000	0.000
\.


--
-- Data for Name: work_order_outputs; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.work_order_outputs (id, work_order_id, recorded_by, recorded_at, good_count, reject_count, shift, batch_code, remarks) FROM stdin;
\.


--
-- Data for Name: work_orders; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.work_orders (id, wo_number, product_id, sales_order_id, sales_order_item_id, mrp_plan_id, parent_wo_id, parent_ncr_id, machine_id, mold_id, quantity_target, quantity_produced, quantity_good, quantity_rejected, scrap_rate, planned_start, planned_end, actual_start, actual_end, status, pause_reason, priority, created_by, created_at, updated_at) FROM stdin;
1	WO-202605-0001	1	1	1	1	\N	\N	\N	\N	100	0	0	0	0.00	2026-05-08 00:00:00	2026-05-09 00:00:00	\N	\N	planned	\N	100	1	2026-05-03 17:07:04	2026-05-03 17:07:04
2	WO-202605-0002	2	2	2	2	\N	\N	\N	\N	150	0	0	0	0.00	2026-05-11 00:00:00	2026-05-12 00:00:00	\N	\N	planned	\N	100	1	2026-05-03 17:07:04	2026-05-03 17:07:04
3	WO-202605-0003	3	3	3	3	\N	\N	\N	\N	200	0	0	0	0.00	2026-05-14 00:00:00	2026-05-15 00:00:00	\N	\N	planned	\N	100	1	2026-05-03 17:07:04	2026-05-03 17:07:04
\.


--
-- Data for Name: workflow_definitions; Type: TABLE DATA; Schema: public; Owner: ogami
--

COPY public.workflow_definitions (id, workflow_type, name, steps, amount_threshold, created_at, updated_at) FROM stdin;
1	leave_request	Leave Request Approval	[{"order":1,"role":"department_head","label":"Department Head"},{"order":2,"role":"hr_officer","label":"HR Officer"}]	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
2	cash_advance	Cash Advance Approval	[{"order":1,"role":"department_head","label":"Department Head"},{"order":2,"role":"finance_officer","label":"Finance \\/ Accounting"},{"order":3,"role":"system_admin","label":"VP \\/ Approver"}]	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
3	company_loan	Company Loan Approval	[{"order":1,"role":"department_head","label":"Department Head"},{"order":2,"role":"production_manager","label":"Manager"},{"order":3,"role":"finance_officer","label":"Finance \\/ Accounting"},{"order":4,"role":"system_admin","label":"VP \\/ Approver"}]	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
4	purchase_request	Purchase Request Approval	[{"order":1,"role":"department_head","label":"Department Head"},{"order":2,"role":"production_manager","label":"Manager"},{"order":3,"role":"purchasing_officer","label":"Purchasing"},{"order":4,"role":"system_admin","label":"VP","threshold":50000}]	50000.00	2026-05-03 17:07:02	2026-05-03 17:07:02
5	purchase_order	Purchase Order Approval	[{"order":1,"role":"purchasing_officer","label":"Purchasing"},{"order":2,"role":"finance_officer","label":"Finance"},{"order":3,"role":"system_admin","label":"VP","threshold":50000}]	50000.00	2026-05-03 17:07:02	2026-05-03 17:07:02
6	payroll_period_finalize	Payroll Period Finalization	[{"order":1,"role":"hr_officer","label":"HR Officer"},{"order":2,"role":"finance_officer","label":"Finance Officer"}]	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
7	bill_payment	Bill Payment Approval	[{"order":1,"role":"finance_officer","label":"Finance Officer"},{"order":2,"role":"system_admin","label":"VP"}]	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
8	journal_entry_post	Journal Entry Posting	[{"order":1,"role":"finance_officer","label":"Finance Officer"}]	\N	2026-05-03 17:07:02	2026-05-03 17:07:02
\.


--
-- Name: accounts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.accounts_id_seq', 51, true);


--
-- Name: approval_records_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.approval_records_id_seq', 1, false);


--
-- Name: approved_suppliers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.approved_suppliers_id_seq', 1, false);


--
-- Name: asset_depreciations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.asset_depreciations_id_seq', 1, false);


--
-- Name: assets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.assets_id_seq', 30, true);


--
-- Name: attendances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.attendances_id_seq', 25, true);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.audit_logs_id_seq', 403, true);


--
-- Name: bank_file_records_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.bank_file_records_id_seq', 1, false);


--
-- Name: bill_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.bill_items_id_seq', 1, false);


--
-- Name: bill_of_materials_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.bill_of_materials_id_seq', 8, true);


--
-- Name: bill_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.bill_payments_id_seq', 1, false);


--
-- Name: bills_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.bills_id_seq', 1, false);


--
-- Name: bom_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.bom_items_id_seq', 26, true);


--
-- Name: clearances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.clearances_id_seq', 3, true);


--
-- Name: collections_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.collections_id_seq', 1, false);


--
-- Name: complaint_8d_reports_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.complaint_8d_reports_id_seq', 1, false);


--
-- Name: customer_complaints_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.customer_complaints_id_seq', 1, true);


--
-- Name: customers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.customers_id_seq', 5, true);


--
-- Name: defect_types_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.defect_types_id_seq', 11, true);


--
-- Name: deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.deliveries_id_seq', 1, false);


--
-- Name: delivery_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.delivery_items_id_seq', 1, false);


--
-- Name: departments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.departments_id_seq', 12, true);


--
-- Name: document_sequences_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.document_sequences_id_seq', 6, true);


--
-- Name: employee_documents_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employee_documents_id_seq', 1, false);


--
-- Name: employee_leave_balances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employee_leave_balances_id_seq', 1, false);


--
-- Name: employee_loans_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employee_loans_id_seq', 1, false);


--
-- Name: employee_property_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employee_property_id_seq', 1, false);


--
-- Name: employee_shift_assignments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employee_shift_assignments_id_seq', 1, false);


--
-- Name: employees_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employees_id_seq', 5, true);


--
-- Name: employment_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.employment_history_id_seq', 1, false);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: goods_receipt_notes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.goods_receipt_notes_id_seq', 1, false);


--
-- Name: government_contribution_tables_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.government_contribution_tables_id_seq', 62, true);


--
-- Name: grn_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.grn_items_id_seq', 1, false);


--
-- Name: holidays_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.holidays_id_seq', 21, true);


--
-- Name: inspection_measurements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.inspection_measurements_id_seq', 1, false);


--
-- Name: inspection_spec_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.inspection_spec_items_id_seq', 1, false);


--
-- Name: inspection_specs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.inspection_specs_id_seq', 1, false);


--
-- Name: inspections_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.inspections_id_seq', 1, false);


--
-- Name: invoice_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.invoice_items_id_seq', 1, false);


--
-- Name: invoices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.invoices_id_seq', 1, false);


--
-- Name: item_categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.item_categories_id_seq', 7, true);


--
-- Name: items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.items_id_seq', 12, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: journal_entries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.journal_entries_id_seq', 1, false);


--
-- Name: journal_entry_lines_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.journal_entry_lines_id_seq', 1, false);


--
-- Name: leave_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.leave_requests_id_seq', 1, false);


--
-- Name: leave_types_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.leave_types_id_seq', 8, true);


--
-- Name: loan_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.loan_payments_id_seq', 1, false);


--
-- Name: machine_downtimes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.machine_downtimes_id_seq', 1, false);


--
-- Name: machines_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.machines_id_seq', 12, true);


--
-- Name: maintenance_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.maintenance_logs_id_seq', 3, true);


--
-- Name: maintenance_schedules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.maintenance_schedules_id_seq', 10, true);


--
-- Name: maintenance_work_orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.maintenance_work_orders_id_seq', 4, true);


--
-- Name: material_issue_slip_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.material_issue_slip_items_id_seq', 1, false);


--
-- Name: material_issue_slips_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.material_issue_slips_id_seq', 1, false);


--
-- Name: material_reservations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.material_reservations_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.migrations_id_seq', 109, true);


--
-- Name: mold_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.mold_history_id_seq', 15, true);


--
-- Name: molds_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.molds_id_seq', 15, true);


--
-- Name: mrp_plans_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.mrp_plans_id_seq', 3, true);


--
-- Name: ncr_actions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.ncr_actions_id_seq', 1, false);


--
-- Name: non_conformance_reports_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.non_conformance_reports_id_seq', 1, false);


--
-- Name: notification_preferences_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.notification_preferences_id_seq', 1, false);


--
-- Name: overtime_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.overtime_requests_id_seq', 1, false);


--
-- Name: password_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.password_history_id_seq', 1, false);


--
-- Name: payroll_adjustments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.payroll_adjustments_id_seq', 1, false);


--
-- Name: payroll_deduction_details_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.payroll_deduction_details_id_seq', 1, false);


--
-- Name: payroll_periods_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.payroll_periods_id_seq', 1, false);


--
-- Name: payrolls_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.payrolls_id_seq', 1, false);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.permissions_id_seq', 144, true);


--
-- Name: positions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.positions_id_seq', 31, true);


--
-- Name: product_price_agreements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.product_price_agreements_id_seq', 15, true);


--
-- Name: production_schedules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.production_schedules_id_seq', 1, false);


--
-- Name: products_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.products_id_seq', 8, true);


--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.purchase_order_items_id_seq', 1, false);


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.purchase_orders_id_seq', 1, false);


--
-- Name: purchase_request_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.purchase_request_items_id_seq', 1, false);


--
-- Name: purchase_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.purchase_requests_id_seq', 1, false);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.roles_id_seq', 12, true);


--
-- Name: sales_order_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.sales_order_items_id_seq', 5, true);


--
-- Name: sales_orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.sales_orders_id_seq', 5, true);


--
-- Name: settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.settings_id_seq', 28, true);


--
-- Name: shifts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.shifts_id_seq', 4, true);


--
-- Name: shipment_documents_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.shipment_documents_id_seq', 1, false);


--
-- Name: shipments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.shipments_id_seq', 1, false);


--
-- Name: spare_part_usage_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.spare_part_usage_id_seq', 1, false);


--
-- Name: stock_levels_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.stock_levels_id_seq', 12, true);


--
-- Name: stock_movements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.stock_movements_id_seq', 1, false);


--
-- Name: thirteenth_month_accruals_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.thirteenth_month_accruals_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.users_id_seq', 1, true);


--
-- Name: vehicles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.vehicles_id_seq', 3, true);


--
-- Name: vendors_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.vendors_id_seq', 4, true);


--
-- Name: warehouse_locations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.warehouse_locations_id_seq', 72, true);


--
-- Name: warehouse_zones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.warehouse_zones_id_seq', 5, true);


--
-- Name: warehouses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.warehouses_id_seq', 1, true);


--
-- Name: work_order_defects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.work_order_defects_id_seq', 1, false);


--
-- Name: work_order_materials_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.work_order_materials_id_seq', 9, true);


--
-- Name: work_order_outputs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.work_order_outputs_id_seq', 1, false);


--
-- Name: work_orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.work_orders_id_seq', 3, true);


--
-- Name: workflow_definitions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: ogami
--

SELECT pg_catalog.setval('public.workflow_definitions_id_seq', 8, true);


--
-- Name: accounts accounts_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_code_unique UNIQUE (code);


--
-- Name: accounts accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (id);


--
-- Name: approval_records approval_records_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approval_records
    ADD CONSTRAINT approval_records_pkey PRIMARY KEY (id);


--
-- Name: approved_suppliers approved_suppliers_item_vendor_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approved_suppliers
    ADD CONSTRAINT approved_suppliers_item_vendor_unique UNIQUE (item_id, vendor_id);


--
-- Name: approved_suppliers approved_suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approved_suppliers
    ADD CONSTRAINT approved_suppliers_pkey PRIMARY KEY (id);


--
-- Name: asset_depreciations asset_depreciations_asset_id_period_year_period_month_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.asset_depreciations
    ADD CONSTRAINT asset_depreciations_asset_id_period_year_period_month_unique UNIQUE (asset_id, period_year, period_month);


--
-- Name: asset_depreciations asset_depreciations_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.asset_depreciations
    ADD CONSTRAINT asset_depreciations_pkey PRIMARY KEY (id);


--
-- Name: assets assets_asset_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT assets_asset_code_unique UNIQUE (asset_code);


--
-- Name: assets assets_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT assets_pkey PRIMARY KEY (id);


--
-- Name: attendances attendances_employee_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.attendances
    ADD CONSTRAINT attendances_employee_id_date_unique UNIQUE (employee_id, date);


--
-- Name: attendances attendances_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.attendances
    ADD CONSTRAINT attendances_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: bank_file_records bank_file_records_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bank_file_records
    ADD CONSTRAINT bank_file_records_pkey PRIMARY KEY (id);


--
-- Name: bill_items bill_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_items
    ADD CONSTRAINT bill_items_pkey PRIMARY KEY (id);


--
-- Name: bill_of_materials bill_of_materials_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_of_materials
    ADD CONSTRAINT bill_of_materials_pkey PRIMARY KEY (id);


--
-- Name: bill_of_materials bill_of_materials_product_id_version_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_of_materials
    ADD CONSTRAINT bill_of_materials_product_id_version_unique UNIQUE (product_id, version);


--
-- Name: bill_payments bill_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_payments
    ADD CONSTRAINT bill_payments_pkey PRIMARY KEY (id);


--
-- Name: bills bills_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_pkey PRIMARY KEY (id);


--
-- Name: bills bills_vendor_no_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_vendor_no_unique UNIQUE (vendor_id, bill_number);


--
-- Name: bom_items bom_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bom_items
    ADD CONSTRAINT bom_items_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: clearances clearances_clearance_no_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances
    ADD CONSTRAINT clearances_clearance_no_unique UNIQUE (clearance_no);


--
-- Name: clearances clearances_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances
    ADD CONSTRAINT clearances_pkey PRIMARY KEY (id);


--
-- Name: collections collections_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT collections_pkey PRIMARY KEY (id);


--
-- Name: complaint_8d_reports complaint_8d_reports_complaint_id_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.complaint_8d_reports
    ADD CONSTRAINT complaint_8d_reports_complaint_id_unique UNIQUE (complaint_id);


--
-- Name: complaint_8d_reports complaint_8d_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.complaint_8d_reports
    ADD CONSTRAINT complaint_8d_reports_pkey PRIMARY KEY (id);


--
-- Name: customer_complaints customer_complaints_complaint_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_complaint_number_unique UNIQUE (complaint_number);


--
-- Name: customer_complaints customer_complaints_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_pkey PRIMARY KEY (id);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: defect_types defect_types_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.defect_types
    ADD CONSTRAINT defect_types_code_unique UNIQUE (code);


--
-- Name: defect_types defect_types_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.defect_types
    ADD CONSTRAINT defect_types_pkey PRIMARY KEY (id);


--
-- Name: deliveries deliveries_delivery_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_delivery_number_unique UNIQUE (delivery_number);


--
-- Name: deliveries deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_pkey PRIMARY KEY (id);


--
-- Name: delivery_items delivery_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.delivery_items
    ADD CONSTRAINT delivery_items_pkey PRIMARY KEY (id);


--
-- Name: departments departments_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_code_unique UNIQUE (code);


--
-- Name: departments departments_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);


--
-- Name: document_sequences document_sequences_document_type_year_month_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.document_sequences
    ADD CONSTRAINT document_sequences_document_type_year_month_unique UNIQUE (document_type, year, month);


--
-- Name: document_sequences document_sequences_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.document_sequences
    ADD CONSTRAINT document_sequences_pkey PRIMARY KEY (id);


--
-- Name: employee_documents employee_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_documents
    ADD CONSTRAINT employee_documents_pkey PRIMARY KEY (id);


--
-- Name: employee_leave_balances employee_leave_balances_employee_id_leave_type_id_year_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_leave_balances
    ADD CONSTRAINT employee_leave_balances_employee_id_leave_type_id_year_unique UNIQUE (employee_id, leave_type_id, year);


--
-- Name: employee_leave_balances employee_leave_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_leave_balances
    ADD CONSTRAINT employee_leave_balances_pkey PRIMARY KEY (id);


--
-- Name: employee_loans employee_loans_loan_no_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_loans
    ADD CONSTRAINT employee_loans_loan_no_unique UNIQUE (loan_no);


--
-- Name: employee_loans employee_loans_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_loans
    ADD CONSTRAINT employee_loans_pkey PRIMARY KEY (id);


--
-- Name: employee_property employee_property_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_property
    ADD CONSTRAINT employee_property_pkey PRIMARY KEY (id);


--
-- Name: employee_shift_assignments employee_shift_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_shift_assignments
    ADD CONSTRAINT employee_shift_assignments_pkey PRIMARY KEY (id);


--
-- Name: employees employees_employee_no_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_employee_no_unique UNIQUE (employee_no);


--
-- Name: employees employees_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_pkey PRIMARY KEY (id);


--
-- Name: employment_history employment_history_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employment_history
    ADD CONSTRAINT employment_history_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: goods_receipt_notes goods_receipt_notes_grn_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes
    ADD CONSTRAINT goods_receipt_notes_grn_number_unique UNIQUE (grn_number);


--
-- Name: goods_receipt_notes goods_receipt_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes
    ADD CONSTRAINT goods_receipt_notes_pkey PRIMARY KEY (id);


--
-- Name: government_contribution_tables government_contribution_tables_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.government_contribution_tables
    ADD CONSTRAINT government_contribution_tables_pkey PRIMARY KEY (id);


--
-- Name: grn_items grn_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.grn_items
    ADD CONSTRAINT grn_items_pkey PRIMARY KEY (id);


--
-- Name: holidays holidays_date_name_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_date_name_unique UNIQUE (date, name);


--
-- Name: holidays holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_pkey PRIMARY KEY (id);


--
-- Name: inspection_measurements inspection_measurements_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_measurements
    ADD CONSTRAINT inspection_measurements_pkey PRIMARY KEY (id);


--
-- Name: inspection_spec_items inspection_spec_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_spec_items
    ADD CONSTRAINT inspection_spec_items_pkey PRIMARY KEY (id);


--
-- Name: inspection_specs inspection_specs_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_specs
    ADD CONSTRAINT inspection_specs_pkey PRIMARY KEY (id);


--
-- Name: inspection_specs inspection_specs_product_id_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_specs
    ADD CONSTRAINT inspection_specs_product_id_unique UNIQUE (product_id);


--
-- Name: inspections inspections_inspection_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspections
    ADD CONSTRAINT inspections_inspection_number_unique UNIQUE (inspection_number);


--
-- Name: inspections inspections_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspections
    ADD CONSTRAINT inspections_pkey PRIMARY KEY (id);


--
-- Name: invoice_items invoice_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_invoice_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_invoice_number_unique UNIQUE (invoice_number);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: item_categories item_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.item_categories
    ADD CONSTRAINT item_categories_pkey PRIMARY KEY (id);


--
-- Name: items items_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_code_unique UNIQUE (code);


--
-- Name: items items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: journal_entries journal_entries_entry_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_entry_number_unique UNIQUE (entry_number);


--
-- Name: journal_entries journal_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_pkey PRIMARY KEY (id);


--
-- Name: journal_entry_lines journal_entry_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entry_lines
    ADD CONSTRAINT journal_entry_lines_pkey PRIMARY KEY (id);


--
-- Name: leave_requests leave_requests_leave_request_no_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_leave_request_no_unique UNIQUE (leave_request_no);


--
-- Name: leave_requests leave_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_pkey PRIMARY KEY (id);


--
-- Name: leave_types leave_types_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_types
    ADD CONSTRAINT leave_types_code_unique UNIQUE (code);


--
-- Name: leave_types leave_types_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_types
    ADD CONSTRAINT leave_types_pkey PRIMARY KEY (id);


--
-- Name: loan_payments loan_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.loan_payments
    ADD CONSTRAINT loan_payments_pkey PRIMARY KEY (id);


--
-- Name: machine_downtimes machine_downtimes_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machine_downtimes
    ADD CONSTRAINT machine_downtimes_pkey PRIMARY KEY (id);


--
-- Name: machines machines_machine_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_machine_code_unique UNIQUE (machine_code);


--
-- Name: machines machines_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_pkey PRIMARY KEY (id);


--
-- Name: maintenance_logs maintenance_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_pkey PRIMARY KEY (id);


--
-- Name: maintenance_schedules maintenance_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_schedules
    ADD CONSTRAINT maintenance_schedules_pkey PRIMARY KEY (id);


--
-- Name: maintenance_work_orders maintenance_work_orders_mwo_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_work_orders
    ADD CONSTRAINT maintenance_work_orders_mwo_number_unique UNIQUE (mwo_number);


--
-- Name: maintenance_work_orders maintenance_work_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_work_orders
    ADD CONSTRAINT maintenance_work_orders_pkey PRIMARY KEY (id);


--
-- Name: material_issue_slip_items material_issue_slip_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slip_items
    ADD CONSTRAINT material_issue_slip_items_pkey PRIMARY KEY (id);


--
-- Name: material_issue_slips material_issue_slips_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slips
    ADD CONSTRAINT material_issue_slips_pkey PRIMARY KEY (id);


--
-- Name: material_issue_slips material_issue_slips_slip_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slips
    ADD CONSTRAINT material_issue_slips_slip_number_unique UNIQUE (slip_number);


--
-- Name: material_reservations material_reservations_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_reservations
    ADD CONSTRAINT material_reservations_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: mold_history mold_history_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mold_history
    ADD CONSTRAINT mold_history_pkey PRIMARY KEY (id);


--
-- Name: mold_machine_compatibility mold_machine_compatibility_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mold_machine_compatibility
    ADD CONSTRAINT mold_machine_compatibility_pkey PRIMARY KEY (mold_id, machine_id);


--
-- Name: molds molds_mold_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.molds
    ADD CONSTRAINT molds_mold_code_unique UNIQUE (mold_code);


--
-- Name: molds molds_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.molds
    ADD CONSTRAINT molds_pkey PRIMARY KEY (id);


--
-- Name: mrp_plans mrp_plans_mrp_plan_no_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mrp_plans
    ADD CONSTRAINT mrp_plans_mrp_plan_no_unique UNIQUE (mrp_plan_no);


--
-- Name: mrp_plans mrp_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mrp_plans
    ADD CONSTRAINT mrp_plans_pkey PRIMARY KEY (id);


--
-- Name: ncr_actions ncr_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.ncr_actions
    ADD CONSTRAINT ncr_actions_pkey PRIMARY KEY (id);


--
-- Name: non_conformance_reports non_conformance_reports_ncr_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_ncr_number_unique UNIQUE (ncr_number);


--
-- Name: non_conformance_reports non_conformance_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_pkey PRIMARY KEY (id);


--
-- Name: notification_preferences notification_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_pkey PRIMARY KEY (id);


--
-- Name: notification_preferences notification_preferences_user_id_notification_type_channel_uniq; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_user_id_notification_type_channel_uniq UNIQUE (user_id, notification_type, channel);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: overtime_requests overtime_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.overtime_requests
    ADD CONSTRAINT overtime_requests_pkey PRIMARY KEY (id);


--
-- Name: password_history password_history_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.password_history
    ADD CONSTRAINT password_history_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: payroll_adjustments payroll_adjustments_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments
    ADD CONSTRAINT payroll_adjustments_pkey PRIMARY KEY (id);


--
-- Name: payroll_deduction_details payroll_deduction_details_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_deduction_details
    ADD CONSTRAINT payroll_deduction_details_pkey PRIMARY KEY (id);


--
-- Name: payroll_periods payroll_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_pkey PRIMARY KEY (id);


--
-- Name: payrolls payrolls_payroll_period_id_employee_id_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payrolls
    ADD CONSTRAINT payrolls_payroll_period_id_employee_id_unique UNIQUE (payroll_period_id, employee_id);


--
-- Name: payrolls payrolls_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payrolls
    ADD CONSTRAINT payrolls_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_slug_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_slug_unique UNIQUE (slug);


--
-- Name: positions positions_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.positions
    ADD CONSTRAINT positions_pkey PRIMARY KEY (id);


--
-- Name: product_price_agreements product_price_agreements_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.product_price_agreements
    ADD CONSTRAINT product_price_agreements_pkey PRIMARY KEY (id);


--
-- Name: production_schedules production_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.production_schedules
    ADD CONSTRAINT production_schedules_pkey PRIMARY KEY (id);


--
-- Name: products products_part_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_part_number_unique UNIQUE (part_number);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: purchase_order_items purchase_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_po_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_po_number_unique UNIQUE (po_number);


--
-- Name: purchase_request_items purchase_request_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_request_items
    ADD CONSTRAINT purchase_request_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_requests purchase_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_requests
    ADD CONSTRAINT purchase_requests_pkey PRIMARY KEY (id);


--
-- Name: purchase_requests purchase_requests_pr_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_requests
    ADD CONSTRAINT purchase_requests_pr_number_unique UNIQUE (pr_number);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_slug_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_slug_unique UNIQUE (slug);


--
-- Name: sales_order_items sales_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_pkey PRIMARY KEY (id);


--
-- Name: sales_orders sales_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_pkey PRIMARY KEY (id);


--
-- Name: sales_orders sales_orders_so_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_so_number_unique UNIQUE (so_number);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: settings settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_key_unique UNIQUE (key);


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (id);


--
-- Name: shifts shifts_name_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shifts
    ADD CONSTRAINT shifts_name_unique UNIQUE (name);


--
-- Name: shifts shifts_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shifts
    ADD CONSTRAINT shifts_pkey PRIMARY KEY (id);


--
-- Name: shipment_documents shipment_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipment_documents
    ADD CONSTRAINT shipment_documents_pkey PRIMARY KEY (id);


--
-- Name: shipments shipments_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipments
    ADD CONSTRAINT shipments_pkey PRIMARY KEY (id);


--
-- Name: shipments shipments_shipment_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipments
    ADD CONSTRAINT shipments_shipment_number_unique UNIQUE (shipment_number);


--
-- Name: spare_part_usage spare_part_usage_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.spare_part_usage
    ADD CONSTRAINT spare_part_usage_pkey PRIMARY KEY (id);


--
-- Name: stock_levels stock_levels_item_loc_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_levels
    ADD CONSTRAINT stock_levels_item_loc_unique UNIQUE (item_id, location_id);


--
-- Name: stock_levels stock_levels_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_levels
    ADD CONSTRAINT stock_levels_pkey PRIMARY KEY (id);


--
-- Name: stock_movements stock_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_pkey PRIMARY KEY (id);


--
-- Name: thirteenth_month_accruals thirteenth_month_accruals_employee_id_year_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.thirteenth_month_accruals
    ADD CONSTRAINT thirteenth_month_accruals_employee_id_year_unique UNIQUE (employee_id, year);


--
-- Name: thirteenth_month_accruals thirteenth_month_accruals_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.thirteenth_month_accruals
    ADD CONSTRAINT thirteenth_month_accruals_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_plate_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_plate_number_unique UNIQUE (plate_number);


--
-- Name: vendors vendors_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.vendors
    ADD CONSTRAINT vendors_pkey PRIMARY KEY (id);


--
-- Name: warehouse_locations warehouse_locations_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_locations
    ADD CONSTRAINT warehouse_locations_code_unique UNIQUE (code);


--
-- Name: warehouse_locations warehouse_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_locations
    ADD CONSTRAINT warehouse_locations_pkey PRIMARY KEY (id);


--
-- Name: warehouse_zones warehouse_zones_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_zones
    ADD CONSTRAINT warehouse_zones_pkey PRIMARY KEY (id);


--
-- Name: warehouse_zones warehouse_zones_wh_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_zones
    ADD CONSTRAINT warehouse_zones_wh_code_unique UNIQUE (warehouse_id, code);


--
-- Name: warehouses warehouses_code_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_code_unique UNIQUE (code);


--
-- Name: warehouses warehouses_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_pkey PRIMARY KEY (id);


--
-- Name: work_order_defects work_order_defects_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_defects
    ADD CONSTRAINT work_order_defects_pkey PRIMARY KEY (id);


--
-- Name: work_order_materials work_order_materials_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_materials
    ADD CONSTRAINT work_order_materials_pkey PRIMARY KEY (id);


--
-- Name: work_order_outputs work_order_outputs_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_outputs
    ADD CONSTRAINT work_order_outputs_pkey PRIMARY KEY (id);


--
-- Name: work_orders work_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_pkey PRIMARY KEY (id);


--
-- Name: work_orders work_orders_wo_number_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_wo_number_unique UNIQUE (wo_number);


--
-- Name: workflow_definitions workflow_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_pkey PRIMARY KEY (id);


--
-- Name: workflow_definitions workflow_definitions_workflow_type_unique; Type: CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_workflow_type_unique UNIQUE (workflow_type);


--
-- Name: accounts_parent_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX accounts_parent_id_index ON public.accounts USING btree (parent_id);


--
-- Name: accounts_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX accounts_type_index ON public.accounts USING btree (type);


--
-- Name: approval_records_action_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX approval_records_action_index ON public.approval_records USING btree (action);


--
-- Name: approval_records_approvable_type_approvable_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX approval_records_approvable_type_approvable_id_index ON public.approval_records USING btree (approvable_type, approvable_id);


--
-- Name: approved_suppliers_is_preferred_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX approved_suppliers_is_preferred_index ON public.approved_suppliers USING btree (is_preferred);


--
-- Name: asset_depreciations_period_year_period_month_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX asset_depreciations_period_year_period_month_index ON public.asset_depreciations USING btree (period_year, period_month);


--
-- Name: assets_acquisition_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX assets_acquisition_date_index ON public.assets USING btree (acquisition_date);


--
-- Name: assets_category_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX assets_category_index ON public.assets USING btree (category);


--
-- Name: assets_department_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX assets_department_id_index ON public.assets USING btree (department_id);


--
-- Name: assets_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX assets_status_index ON public.assets USING btree (status);


--
-- Name: attendances_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX attendances_date_index ON public.attendances USING btree (date);


--
-- Name: attendances_emp_date_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX attendances_emp_date_idx ON public.attendances USING btree (employee_id, date);


--
-- Name: attendances_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX attendances_status_index ON public.attendances USING btree (status);


--
-- Name: audit_logs_action_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX audit_logs_action_index ON public.audit_logs USING btree (action);


--
-- Name: audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at);


--
-- Name: audit_logs_model_created_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX audit_logs_model_created_idx ON public.audit_logs USING btree (model_type, model_id, created_at);


--
-- Name: audit_logs_model_type_model_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX audit_logs_model_type_model_id_index ON public.audit_logs USING btree (model_type, model_id);


--
-- Name: bank_file_records_payroll_period_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bank_file_records_payroll_period_id_index ON public.bank_file_records USING btree (payroll_period_id);


--
-- Name: bill_items_bill_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bill_items_bill_id_index ON public.bill_items USING btree (bill_id);


--
-- Name: bill_items_expense_account_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bill_items_expense_account_id_index ON public.bill_items USING btree (expense_account_id);


--
-- Name: bill_of_materials_product_id_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bill_of_materials_product_id_is_active_index ON public.bill_of_materials USING btree (product_id, is_active);


--
-- Name: bill_payments_bill_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bill_payments_bill_id_index ON public.bill_payments USING btree (bill_id);


--
-- Name: bill_payments_payment_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bill_payments_payment_date_index ON public.bill_payments USING btree (payment_date);


--
-- Name: bills_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bills_date_index ON public.bills USING btree (date);


--
-- Name: bills_due_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bills_due_date_index ON public.bills USING btree (due_date);


--
-- Name: bills_purchase_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bills_purchase_order_id_index ON public.bills USING btree (purchase_order_id);


--
-- Name: bills_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bills_status_index ON public.bills USING btree (status);


--
-- Name: bom_items_bom_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bom_items_bom_id_index ON public.bom_items USING btree (bom_id);


--
-- Name: bom_items_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX bom_items_item_id_index ON public.bom_items USING btree (item_id);


--
-- Name: clearances_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX clearances_employee_id_index ON public.clearances USING btree (employee_id);


--
-- Name: clearances_separation_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX clearances_separation_date_index ON public.clearances USING btree (separation_date);


--
-- Name: clearances_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX clearances_status_index ON public.clearances USING btree (status);


--
-- Name: collections_collection_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX collections_collection_date_index ON public.collections USING btree (collection_date);


--
-- Name: collections_invoice_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX collections_invoice_id_index ON public.collections USING btree (invoice_id);


--
-- Name: customer_complaints_customer_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customer_complaints_customer_id_index ON public.customer_complaints USING btree (customer_id);


--
-- Name: customer_complaints_product_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customer_complaints_product_id_index ON public.customer_complaints USING btree (product_id);


--
-- Name: customer_complaints_received_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customer_complaints_received_date_index ON public.customer_complaints USING btree (received_date);


--
-- Name: customer_complaints_severity_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customer_complaints_severity_index ON public.customer_complaints USING btree (severity);


--
-- Name: customer_complaints_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customer_complaints_status_index ON public.customer_complaints USING btree (status);


--
-- Name: customers_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customers_is_active_index ON public.customers USING btree (is_active);


--
-- Name: customers_name_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX customers_name_index ON public.customers USING btree (name);


--
-- Name: deliveries_sales_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX deliveries_sales_order_id_index ON public.deliveries USING btree (sales_order_id);


--
-- Name: deliveries_scheduled_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX deliveries_scheduled_date_index ON public.deliveries USING btree (scheduled_date);


--
-- Name: deliveries_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX deliveries_status_index ON public.deliveries USING btree (status);


--
-- Name: delivery_items_delivery_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX delivery_items_delivery_id_index ON public.delivery_items USING btree (delivery_id);


--
-- Name: delivery_items_sales_order_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX delivery_items_sales_order_item_id_index ON public.delivery_items USING btree (sales_order_item_id);


--
-- Name: departments_head_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX departments_head_employee_id_index ON public.departments USING btree (head_employee_id);


--
-- Name: departments_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX departments_is_active_index ON public.departments USING btree (is_active);


--
-- Name: departments_parent_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX departments_parent_id_index ON public.departments USING btree (parent_id);


--
-- Name: employee_documents_document_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_documents_document_type_index ON public.employee_documents USING btree (document_type);


--
-- Name: employee_documents_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_documents_employee_id_index ON public.employee_documents USING btree (employee_id);


--
-- Name: employee_leave_balances_employee_id_year_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_leave_balances_employee_id_year_index ON public.employee_leave_balances USING btree (employee_id, year);


--
-- Name: employee_loans_employee_id_loan_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_loans_employee_id_loan_type_index ON public.employee_loans USING btree (employee_id, loan_type);


--
-- Name: employee_loans_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_loans_status_index ON public.employee_loans USING btree (status);


--
-- Name: employee_property_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_property_employee_id_index ON public.employee_property USING btree (employee_id);


--
-- Name: employee_property_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_property_status_index ON public.employee_property USING btree (status);


--
-- Name: employee_shift_assignments_employee_id_effective_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_shift_assignments_employee_id_effective_date_index ON public.employee_shift_assignments USING btree (employee_id, effective_date);


--
-- Name: employee_shift_assignments_shift_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employee_shift_assignments_shift_id_index ON public.employee_shift_assignments USING btree (shift_id);


--
-- Name: employees_date_hired_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employees_date_hired_index ON public.employees USING btree (date_hired);


--
-- Name: employees_department_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employees_department_id_index ON public.employees USING btree (department_id);


--
-- Name: employees_employment_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employees_employment_type_index ON public.employees USING btree (employment_type);


--
-- Name: employees_pay_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employees_pay_type_index ON public.employees USING btree (pay_type);


--
-- Name: employees_position_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employees_position_id_index ON public.employees USING btree (position_id);


--
-- Name: employees_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employees_status_index ON public.employees USING btree (status);


--
-- Name: employment_history_change_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employment_history_change_type_index ON public.employment_history USING btree (change_type);


--
-- Name: employment_history_employee_id_effective_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX employment_history_employee_id_effective_date_index ON public.employment_history USING btree (employee_id, effective_date);


--
-- Name: goods_receipt_notes_purchase_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX goods_receipt_notes_purchase_order_id_index ON public.goods_receipt_notes USING btree (purchase_order_id);


--
-- Name: goods_receipt_notes_received_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX goods_receipt_notes_received_date_index ON public.goods_receipt_notes USING btree (received_date);


--
-- Name: goods_receipt_notes_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX goods_receipt_notes_status_index ON public.goods_receipt_notes USING btree (status);


--
-- Name: goods_receipt_notes_vendor_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX goods_receipt_notes_vendor_id_index ON public.goods_receipt_notes USING btree (vendor_id);


--
-- Name: government_contribution_tables_agency_bracket_min_bracket_max_i; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX government_contribution_tables_agency_bracket_min_bracket_max_i ON public.government_contribution_tables USING btree (agency, bracket_min, bracket_max);


--
-- Name: government_contribution_tables_agency_effective_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX government_contribution_tables_agency_effective_date_index ON public.government_contribution_tables USING btree (agency, effective_date);


--
-- Name: government_contribution_tables_agency_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX government_contribution_tables_agency_is_active_index ON public.government_contribution_tables USING btree (agency, is_active);


--
-- Name: grn_items_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX grn_items_item_id_index ON public.grn_items USING btree (item_id);


--
-- Name: grn_items_purchase_order_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX grn_items_purchase_order_item_id_index ON public.grn_items USING btree (purchase_order_item_id);


--
-- Name: holidays_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX holidays_date_index ON public.holidays USING btree (date);


--
-- Name: inspection_measurements_inspection_id_sample_index_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspection_measurements_inspection_id_sample_index_index ON public.inspection_measurements USING btree (inspection_id, sample_index);


--
-- Name: inspection_measurements_inspection_spec_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspection_measurements_inspection_spec_item_id_index ON public.inspection_measurements USING btree (inspection_spec_item_id);


--
-- Name: inspection_measurements_is_pass_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspection_measurements_is_pass_index ON public.inspection_measurements USING btree (is_pass);


--
-- Name: inspection_spec_items_inspection_spec_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspection_spec_items_inspection_spec_id_index ON public.inspection_spec_items USING btree (inspection_spec_id);


--
-- Name: inspection_spec_items_parameter_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspection_spec_items_parameter_type_index ON public.inspection_spec_items USING btree (parameter_type);


--
-- Name: inspection_specs_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspection_specs_is_active_index ON public.inspection_specs USING btree (is_active);


--
-- Name: inspections_completed_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_completed_at_index ON public.inspections USING btree (completed_at);


--
-- Name: inspections_entity_type_entity_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_entity_type_entity_id_index ON public.inspections USING btree (entity_type, entity_id);


--
-- Name: inspections_inspector_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_inspector_id_index ON public.inspections USING btree (inspector_id);


--
-- Name: inspections_product_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_product_id_index ON public.inspections USING btree (product_id);


--
-- Name: inspections_stage_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_stage_index ON public.inspections USING btree (stage);


--
-- Name: inspections_stage_status_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_stage_status_idx ON public.inspections USING btree (stage, status);


--
-- Name: inspections_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX inspections_status_index ON public.inspections USING btree (status);


--
-- Name: invoice_items_invoice_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX invoice_items_invoice_id_index ON public.invoice_items USING btree (invoice_id);


--
-- Name: invoice_items_revenue_account_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX invoice_items_revenue_account_id_index ON public.invoice_items USING btree (revenue_account_id);


--
-- Name: invoices_customer_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX invoices_customer_id_index ON public.invoices USING btree (customer_id);


--
-- Name: invoices_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX invoices_date_index ON public.invoices USING btree (date);


--
-- Name: invoices_due_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX invoices_due_date_index ON public.invoices USING btree (due_date);


--
-- Name: invoices_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX invoices_status_index ON public.invoices USING btree (status);


--
-- Name: item_categories_parent_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX item_categories_parent_id_index ON public.item_categories USING btree (parent_id);


--
-- Name: items_category_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX items_category_id_index ON public.items USING btree (category_id);


--
-- Name: items_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX items_is_active_index ON public.items USING btree (is_active);


--
-- Name: items_is_critical_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX items_is_critical_index ON public.items USING btree (is_critical);


--
-- Name: items_item_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX items_item_type_index ON public.items USING btree (item_type);


--
-- Name: jel_acct_je_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX jel_acct_je_idx ON public.journal_entry_lines USING btree (account_id, journal_entry_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: journal_entries_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX journal_entries_date_index ON public.journal_entries USING btree (date);


--
-- Name: journal_entries_reference_type_reference_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX journal_entries_reference_type_reference_id_index ON public.journal_entries USING btree (reference_type, reference_id);


--
-- Name: journal_entries_reversed_by_entry_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX journal_entries_reversed_by_entry_id_index ON public.journal_entries USING btree (reversed_by_entry_id);


--
-- Name: journal_entries_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX journal_entries_status_index ON public.journal_entries USING btree (status);


--
-- Name: journal_entry_lines_account_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX journal_entry_lines_account_id_index ON public.journal_entry_lines USING btree (account_id);


--
-- Name: journal_entry_lines_journal_entry_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX journal_entry_lines_journal_entry_id_index ON public.journal_entry_lines USING btree (journal_entry_id);


--
-- Name: leave_requests_employee_id_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX leave_requests_employee_id_status_index ON public.leave_requests USING btree (employee_id, status);


--
-- Name: leave_requests_start_date_end_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX leave_requests_start_date_end_date_index ON public.leave_requests USING btree (start_date, end_date);


--
-- Name: leave_requests_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX leave_requests_status_index ON public.leave_requests USING btree (status);


--
-- Name: leave_types_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX leave_types_is_active_index ON public.leave_types USING btree (is_active);


--
-- Name: loan_payments_loan_id_payment_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX loan_payments_loan_id_payment_date_index ON public.loan_payments USING btree (loan_id, payment_date);


--
-- Name: loan_payments_payroll_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX loan_payments_payroll_id_index ON public.loan_payments USING btree (payroll_id);


--
-- Name: machine_downtimes_category_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX machine_downtimes_category_index ON public.machine_downtimes USING btree (category);


--
-- Name: machine_downtimes_machine_id_start_time_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX machine_downtimes_machine_id_start_time_index ON public.machine_downtimes USING btree (machine_id, start_time);


--
-- Name: machines_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX machines_status_index ON public.machines USING btree (status);


--
-- Name: maintenance_logs_work_order_id_created_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_logs_work_order_id_created_at_index ON public.maintenance_logs USING btree (work_order_id, created_at);


--
-- Name: maintenance_schedules_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_schedules_is_active_index ON public.maintenance_schedules USING btree (is_active);


--
-- Name: maintenance_schedules_maintainable_type_maintainable_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_schedules_maintainable_type_maintainable_id_index ON public.maintenance_schedules USING btree (maintainable_type, maintainable_id);


--
-- Name: maintenance_schedules_next_due_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_schedules_next_due_at_index ON public.maintenance_schedules USING btree (next_due_at);


--
-- Name: maintenance_work_orders_assigned_to_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_work_orders_assigned_to_index ON public.maintenance_work_orders USING btree (assigned_to);


--
-- Name: maintenance_work_orders_completed_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_work_orders_completed_at_index ON public.maintenance_work_orders USING btree (completed_at);


--
-- Name: maintenance_work_orders_maintainable_type_maintainable_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_work_orders_maintainable_type_maintainable_id_index ON public.maintenance_work_orders USING btree (maintainable_type, maintainable_id);


--
-- Name: maintenance_work_orders_priority_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_work_orders_priority_index ON public.maintenance_work_orders USING btree (priority);


--
-- Name: maintenance_work_orders_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_work_orders_status_index ON public.maintenance_work_orders USING btree (status);


--
-- Name: maintenance_work_orders_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX maintenance_work_orders_type_index ON public.maintenance_work_orders USING btree (type);


--
-- Name: material_issue_slip_items_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_issue_slip_items_item_id_index ON public.material_issue_slip_items USING btree (item_id);


--
-- Name: material_issue_slip_items_material_issue_slip_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_issue_slip_items_material_issue_slip_id_index ON public.material_issue_slip_items USING btree (material_issue_slip_id);


--
-- Name: material_issue_slip_items_material_reservation_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_issue_slip_items_material_reservation_id_index ON public.material_issue_slip_items USING btree (material_reservation_id);


--
-- Name: material_issue_slips_issued_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_issue_slips_issued_date_index ON public.material_issue_slips USING btree (issued_date);


--
-- Name: material_issue_slips_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_issue_slips_status_index ON public.material_issue_slips USING btree (status);


--
-- Name: material_issue_slips_work_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_issue_slips_work_order_id_index ON public.material_issue_slips USING btree (work_order_id);


--
-- Name: material_reservations_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_reservations_item_id_index ON public.material_reservations USING btree (item_id);


--
-- Name: material_reservations_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_reservations_status_index ON public.material_reservations USING btree (status);


--
-- Name: material_reservations_work_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX material_reservations_work_order_id_index ON public.material_reservations USING btree (work_order_id);


--
-- Name: mold_history_mold_id_event_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX mold_history_mold_id_event_date_index ON public.mold_history USING btree (mold_id, event_date);


--
-- Name: molds_product_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX molds_product_id_index ON public.molds USING btree (product_id);


--
-- Name: molds_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX molds_status_index ON public.molds USING btree (status);


--
-- Name: mrp_plans_sales_order_id_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX mrp_plans_sales_order_id_status_index ON public.mrp_plans USING btree (sales_order_id, status);


--
-- Name: ncr_actions_action_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX ncr_actions_action_type_index ON public.ncr_actions USING btree (action_type);


--
-- Name: ncr_actions_ncr_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX ncr_actions_ncr_id_index ON public.ncr_actions USING btree (ncr_id);


--
-- Name: non_conformance_reports_closed_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_closed_at_index ON public.non_conformance_reports USING btree (closed_at);


--
-- Name: non_conformance_reports_complaint_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_complaint_id_index ON public.non_conformance_reports USING btree (complaint_id);


--
-- Name: non_conformance_reports_disposition_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_disposition_index ON public.non_conformance_reports USING btree (disposition);


--
-- Name: non_conformance_reports_inspection_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_inspection_id_index ON public.non_conformance_reports USING btree (inspection_id);


--
-- Name: non_conformance_reports_product_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_product_id_index ON public.non_conformance_reports USING btree (product_id);


--
-- Name: non_conformance_reports_severity_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_severity_index ON public.non_conformance_reports USING btree (severity);


--
-- Name: non_conformance_reports_source_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_source_index ON public.non_conformance_reports USING btree (source);


--
-- Name: non_conformance_reports_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX non_conformance_reports_status_index ON public.non_conformance_reports USING btree (status);


--
-- Name: notifications_notifiable_read_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX notifications_notifiable_read_idx ON public.notifications USING btree (notifiable_id, read_at);


--
-- Name: notifications_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX notifications_notifiable_type_notifiable_id_index ON public.notifications USING btree (notifiable_type, notifiable_id);


--
-- Name: overtime_requests_employee_id_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX overtime_requests_employee_id_date_index ON public.overtime_requests USING btree (employee_id, date);


--
-- Name: overtime_requests_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX overtime_requests_status_index ON public.overtime_requests USING btree (status);


--
-- Name: password_history_user_id_created_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX password_history_user_id_created_at_index ON public.password_history USING btree (user_id, created_at);


--
-- Name: payroll_adjustments_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_adjustments_employee_id_index ON public.payroll_adjustments USING btree (employee_id);


--
-- Name: payroll_adjustments_payroll_period_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_adjustments_payroll_period_id_index ON public.payroll_adjustments USING btree (payroll_period_id);


--
-- Name: payroll_adjustments_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_adjustments_status_index ON public.payroll_adjustments USING btree (status);


--
-- Name: payroll_deduction_details_deduction_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_deduction_details_deduction_type_index ON public.payroll_deduction_details USING btree (deduction_type);


--
-- Name: payroll_deduction_details_payroll_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_deduction_details_payroll_id_index ON public.payroll_deduction_details USING btree (payroll_id);


--
-- Name: payroll_periods_is_thirteenth_month_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_periods_is_thirteenth_month_index ON public.payroll_periods USING btree (is_thirteenth_month);


--
-- Name: payroll_periods_period_start_period_end_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_periods_period_start_period_end_index ON public.payroll_periods USING btree (period_start, period_end);


--
-- Name: payroll_periods_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payroll_periods_status_index ON public.payroll_periods USING btree (status);


--
-- Name: payrolls_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payrolls_employee_id_index ON public.payrolls USING btree (employee_id);


--
-- Name: payrolls_period_emp_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX payrolls_period_emp_idx ON public.payrolls USING btree (payroll_period_id, employee_id);


--
-- Name: permissions_module_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX permissions_module_index ON public.permissions USING btree (module);


--
-- Name: positions_department_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX positions_department_id_index ON public.positions USING btree (department_id);


--
-- Name: ppa_lookup_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX ppa_lookup_idx ON public.product_price_agreements USING btree (product_id, customer_id, effective_from);


--
-- Name: product_price_agreements_effective_to_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX product_price_agreements_effective_to_index ON public.product_price_agreements USING btree (effective_to);


--
-- Name: production_schedules_machine_id_scheduled_start_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX production_schedules_machine_id_scheduled_start_index ON public.production_schedules USING btree (machine_id, scheduled_start);


--
-- Name: production_schedules_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX production_schedules_status_index ON public.production_schedules USING btree (status);


--
-- Name: production_schedules_work_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX production_schedules_work_order_id_index ON public.production_schedules USING btree (work_order_id);


--
-- Name: products_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX products_is_active_index ON public.products USING btree (is_active);


--
-- Name: purchase_order_items_purchase_order_id_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_order_items_purchase_order_id_item_id_index ON public.purchase_order_items USING btree (purchase_order_id, item_id);


--
-- Name: purchase_order_items_purchase_request_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_order_items_purchase_request_item_id_index ON public.purchase_order_items USING btree (purchase_request_item_id);


--
-- Name: purchase_orders_expected_delivery_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_orders_expected_delivery_date_index ON public.purchase_orders USING btree (expected_delivery_date);


--
-- Name: purchase_orders_purchase_request_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_orders_purchase_request_id_index ON public.purchase_orders USING btree (purchase_request_id);


--
-- Name: purchase_orders_requires_vp_approval_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_orders_requires_vp_approval_index ON public.purchase_orders USING btree (requires_vp_approval);


--
-- Name: purchase_orders_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_orders_status_index ON public.purchase_orders USING btree (status);


--
-- Name: purchase_orders_vendor_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_orders_vendor_id_index ON public.purchase_orders USING btree (vendor_id);


--
-- Name: purchase_request_items_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_request_items_item_id_index ON public.purchase_request_items USING btree (item_id);


--
-- Name: purchase_request_items_purchase_request_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_request_items_purchase_request_id_index ON public.purchase_request_items USING btree (purchase_request_id);


--
-- Name: purchase_requests_department_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_requests_department_id_index ON public.purchase_requests USING btree (department_id);


--
-- Name: purchase_requests_is_auto_generated_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_requests_is_auto_generated_index ON public.purchase_requests USING btree (is_auto_generated);


--
-- Name: purchase_requests_mrp_plan_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_requests_mrp_plan_id_index ON public.purchase_requests USING btree (mrp_plan_id);


--
-- Name: purchase_requests_priority_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_requests_priority_index ON public.purchase_requests USING btree (priority);


--
-- Name: purchase_requests_requested_by_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_requests_requested_by_index ON public.purchase_requests USING btree (requested_by);


--
-- Name: purchase_requests_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX purchase_requests_status_index ON public.purchase_requests USING btree (status);


--
-- Name: sales_order_items_delivery_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_order_items_delivery_date_index ON public.sales_order_items USING btree (delivery_date);


--
-- Name: sales_order_items_product_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_order_items_product_id_index ON public.sales_order_items USING btree (product_id);


--
-- Name: sales_order_items_sales_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_order_items_sales_order_id_index ON public.sales_order_items USING btree (sales_order_id);


--
-- Name: sales_orders_customer_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_orders_customer_id_index ON public.sales_orders USING btree (customer_id);


--
-- Name: sales_orders_date_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_orders_date_index ON public.sales_orders USING btree (date);


--
-- Name: sales_orders_mrp_plan_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_orders_mrp_plan_id_index ON public.sales_orders USING btree (mrp_plan_id);


--
-- Name: sales_orders_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sales_orders_status_index ON public.sales_orders USING btree (status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: settings_group_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX settings_group_index ON public.settings USING btree ("group");


--
-- Name: shifts_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX shifts_is_active_index ON public.shifts USING btree (is_active);


--
-- Name: shipment_documents_shipment_id_document_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX shipment_documents_shipment_id_document_type_index ON public.shipment_documents USING btree (shipment_id, document_type);


--
-- Name: shipments_eta_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX shipments_eta_index ON public.shipments USING btree (eta);


--
-- Name: shipments_purchase_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX shipments_purchase_order_id_index ON public.shipments USING btree (purchase_order_id);


--
-- Name: shipments_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX shipments_status_index ON public.shipments USING btree (status);


--
-- Name: spare_part_usage_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX spare_part_usage_item_id_index ON public.spare_part_usage USING btree (item_id);


--
-- Name: spare_part_usage_work_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX spare_part_usage_work_order_id_index ON public.spare_part_usage USING btree (work_order_id);


--
-- Name: stock_levels_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX stock_levels_item_id_index ON public.stock_levels USING btree (item_id);


--
-- Name: stock_levels_location_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX stock_levels_location_id_index ON public.stock_levels USING btree (location_id);


--
-- Name: stock_movements_item_created_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX stock_movements_item_created_idx ON public.stock_movements USING btree (item_id, created_at);


--
-- Name: stock_movements_item_id_created_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX stock_movements_item_id_created_at_index ON public.stock_movements USING btree (item_id, created_at);


--
-- Name: stock_movements_movement_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX stock_movements_movement_type_index ON public.stock_movements USING btree (movement_type);


--
-- Name: stock_movements_reference_type_reference_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX stock_movements_reference_type_reference_id_index ON public.stock_movements USING btree (reference_type, reference_id);


--
-- Name: thirteenth_month_accruals_year_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX thirteenth_month_accruals_year_index ON public.thirteenth_month_accruals USING btree (year);


--
-- Name: users_employee_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX users_employee_id_index ON public.users USING btree (employee_id);


--
-- Name: vehicles_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX vehicles_status_index ON public.vehicles USING btree (status);


--
-- Name: vendors_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX vendors_is_active_index ON public.vendors USING btree (is_active);


--
-- Name: vendors_name_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX vendors_name_index ON public.vendors USING btree (name);


--
-- Name: warehouse_locations_zone_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX warehouse_locations_zone_id_index ON public.warehouse_locations USING btree (zone_id);


--
-- Name: warehouse_zones_zone_type_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX warehouse_zones_zone_type_index ON public.warehouse_zones USING btree (zone_type);


--
-- Name: warehouses_is_active_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX warehouses_is_active_index ON public.warehouses USING btree (is_active);


--
-- Name: wo_status_plan_idx; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX wo_status_plan_idx ON public.work_orders USING btree (status, planned_start);


--
-- Name: work_order_defects_output_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_order_defects_output_id_index ON public.work_order_defects USING btree (output_id);


--
-- Name: work_order_materials_item_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_order_materials_item_id_index ON public.work_order_materials USING btree (item_id);


--
-- Name: work_order_materials_work_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_order_materials_work_order_id_index ON public.work_order_materials USING btree (work_order_id);


--
-- Name: work_order_outputs_work_order_id_recorded_at_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_order_outputs_work_order_id_recorded_at_index ON public.work_order_outputs USING btree (work_order_id, recorded_at);


--
-- Name: work_orders_machine_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_orders_machine_id_index ON public.work_orders USING btree (machine_id);


--
-- Name: work_orders_mrp_plan_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_orders_mrp_plan_id_index ON public.work_orders USING btree (mrp_plan_id);


--
-- Name: work_orders_planned_start_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_orders_planned_start_index ON public.work_orders USING btree (planned_start);


--
-- Name: work_orders_sales_order_id_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_orders_sales_order_id_index ON public.work_orders USING btree (sales_order_id);


--
-- Name: work_orders_status_index; Type: INDEX; Schema: public; Owner: ogami
--

CREATE INDEX work_orders_status_index ON public.work_orders USING btree (status);


--
-- Name: accounts accounts_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.accounts(id) ON DELETE SET NULL;


--
-- Name: approval_records approval_records_approver_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approval_records
    ADD CONSTRAINT approval_records_approver_id_foreign FOREIGN KEY (approver_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: approved_suppliers approved_suppliers_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approved_suppliers
    ADD CONSTRAINT approved_suppliers_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE CASCADE;


--
-- Name: approved_suppliers approved_suppliers_vendor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.approved_suppliers
    ADD CONSTRAINT approved_suppliers_vendor_id_foreign FOREIGN KEY (vendor_id) REFERENCES public.vendors(id) ON DELETE CASCADE;


--
-- Name: asset_depreciations asset_depreciations_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.asset_depreciations
    ADD CONSTRAINT asset_depreciations_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE CASCADE;


--
-- Name: asset_depreciations asset_depreciations_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.asset_depreciations
    ADD CONSTRAINT asset_depreciations_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: assets assets_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT assets_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: attendances attendances_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.attendances
    ADD CONSTRAINT attendances_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: attendances attendances_shift_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.attendances
    ADD CONSTRAINT attendances_shift_id_foreign FOREIGN KEY (shift_id) REFERENCES public.shifts(id);


--
-- Name: audit_logs audit_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_file_records bank_file_records_generated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bank_file_records
    ADD CONSTRAINT bank_file_records_generated_by_foreign FOREIGN KEY (generated_by) REFERENCES public.users(id);


--
-- Name: bank_file_records bank_file_records_payroll_period_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bank_file_records
    ADD CONSTRAINT bank_file_records_payroll_period_id_foreign FOREIGN KEY (payroll_period_id) REFERENCES public.payroll_periods(id);


--
-- Name: bill_items bill_items_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_items
    ADD CONSTRAINT bill_items_bill_id_foreign FOREIGN KEY (bill_id) REFERENCES public.bills(id) ON DELETE CASCADE;


--
-- Name: bill_items bill_items_expense_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_items
    ADD CONSTRAINT bill_items_expense_account_id_foreign FOREIGN KEY (expense_account_id) REFERENCES public.accounts(id) ON DELETE RESTRICT;


--
-- Name: bill_of_materials bill_of_materials_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_of_materials
    ADD CONSTRAINT bill_of_materials_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: bill_payments bill_payments_bill_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_payments
    ADD CONSTRAINT bill_payments_bill_id_foreign FOREIGN KEY (bill_id) REFERENCES public.bills(id) ON DELETE RESTRICT;


--
-- Name: bill_payments bill_payments_cash_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_payments
    ADD CONSTRAINT bill_payments_cash_account_id_foreign FOREIGN KEY (cash_account_id) REFERENCES public.accounts(id) ON DELETE RESTRICT;


--
-- Name: bill_payments bill_payments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_payments
    ADD CONSTRAINT bill_payments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bill_payments bill_payments_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bill_payments
    ADD CONSTRAINT bill_payments_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: bills bills_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bills bills_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: bills bills_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE SET NULL;


--
-- Name: bills bills_three_way_overridden_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_three_way_overridden_by_foreign FOREIGN KEY (three_way_overridden_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bills bills_vendor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bills
    ADD CONSTRAINT bills_vendor_id_foreign FOREIGN KEY (vendor_id) REFERENCES public.vendors(id) ON DELETE RESTRICT;


--
-- Name: bom_items bom_items_bom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bom_items
    ADD CONSTRAINT bom_items_bom_id_foreign FOREIGN KEY (bom_id) REFERENCES public.bill_of_materials(id) ON DELETE CASCADE;


--
-- Name: bom_items bom_items_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.bom_items
    ADD CONSTRAINT bom_items_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: clearances clearances_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances
    ADD CONSTRAINT clearances_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id);


--
-- Name: clearances clearances_finalized_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances
    ADD CONSTRAINT clearances_finalized_by_foreign FOREIGN KEY (finalized_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: clearances clearances_initiated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances
    ADD CONSTRAINT clearances_initiated_by_foreign FOREIGN KEY (initiated_by) REFERENCES public.users(id);


--
-- Name: clearances clearances_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.clearances
    ADD CONSTRAINT clearances_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: collections collections_cash_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT collections_cash_account_id_foreign FOREIGN KEY (cash_account_id) REFERENCES public.accounts(id) ON DELETE RESTRICT;


--
-- Name: collections collections_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT collections_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: collections collections_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT collections_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE RESTRICT;


--
-- Name: collections collections_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT collections_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: complaint_8d_reports complaint_8d_reports_complaint_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.complaint_8d_reports
    ADD CONSTRAINT complaint_8d_reports_complaint_id_foreign FOREIGN KEY (complaint_id) REFERENCES public.customer_complaints(id) ON DELETE CASCADE;


--
-- Name: complaint_8d_reports complaint_8d_reports_finalized_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.complaint_8d_reports
    ADD CONSTRAINT complaint_8d_reports_finalized_by_foreign FOREIGN KEY (finalized_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customer_complaints customer_complaints_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: customer_complaints customer_complaints_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: customer_complaints customer_complaints_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: customer_complaints customer_complaints_ncr_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_ncr_id_foreign FOREIGN KEY (ncr_id) REFERENCES public.non_conformance_reports(id) ON DELETE SET NULL;


--
-- Name: customer_complaints customer_complaints_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: customer_complaints customer_complaints_replacement_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_replacement_work_order_id_foreign FOREIGN KEY (replacement_work_order_id) REFERENCES public.work_orders(id) ON DELETE SET NULL;


--
-- Name: customer_complaints customer_complaints_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.customer_complaints
    ADD CONSTRAINT customer_complaints_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE SET NULL;


--
-- Name: deliveries deliveries_confirmed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_confirmed_by_foreign FOREIGN KEY (confirmed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deliveries deliveries_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: deliveries deliveries_driver_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_driver_id_foreign FOREIGN KEY (driver_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deliveries deliveries_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: deliveries deliveries_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE CASCADE;


--
-- Name: deliveries deliveries_vehicle_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.deliveries
    ADD CONSTRAINT deliveries_vehicle_id_foreign FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id) ON DELETE SET NULL;


--
-- Name: delivery_items delivery_items_delivery_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.delivery_items
    ADD CONSTRAINT delivery_items_delivery_id_foreign FOREIGN KEY (delivery_id) REFERENCES public.deliveries(id) ON DELETE CASCADE;


--
-- Name: delivery_items delivery_items_inspection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.delivery_items
    ADD CONSTRAINT delivery_items_inspection_id_foreign FOREIGN KEY (inspection_id) REFERENCES public.inspections(id) ON DELETE SET NULL;


--
-- Name: delivery_items delivery_items_sales_order_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.delivery_items
    ADD CONSTRAINT delivery_items_sales_order_item_id_foreign FOREIGN KEY (sales_order_item_id) REFERENCES public.sales_order_items(id) ON DELETE CASCADE;


--
-- Name: departments departments_head_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_head_employee_id_foreign FOREIGN KEY (head_employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: departments departments_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: employee_documents employee_documents_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_documents
    ADD CONSTRAINT employee_documents_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: employee_leave_balances employee_leave_balances_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_leave_balances
    ADD CONSTRAINT employee_leave_balances_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: employee_leave_balances employee_leave_balances_leave_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_leave_balances
    ADD CONSTRAINT employee_leave_balances_leave_type_id_foreign FOREIGN KEY (leave_type_id) REFERENCES public.leave_types(id);


--
-- Name: employee_loans employee_loans_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_loans
    ADD CONSTRAINT employee_loans_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: employee_property employee_property_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_property
    ADD CONSTRAINT employee_property_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: employee_shift_assignments employee_shift_assignments_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_shift_assignments
    ADD CONSTRAINT employee_shift_assignments_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: employee_shift_assignments employee_shift_assignments_shift_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employee_shift_assignments
    ADD CONSTRAINT employee_shift_assignments_shift_id_foreign FOREIGN KEY (shift_id) REFERENCES public.shifts(id);


--
-- Name: employees employees_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id);


--
-- Name: employees employees_position_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_position_id_foreign FOREIGN KEY (position_id) REFERENCES public.positions(id);


--
-- Name: employment_history employment_history_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employment_history
    ADD CONSTRAINT employment_history_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: employment_history employment_history_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.employment_history
    ADD CONSTRAINT employment_history_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: goods_receipt_notes goods_receipt_notes_accepted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes
    ADD CONSTRAINT goods_receipt_notes_accepted_by_foreign FOREIGN KEY (accepted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: goods_receipt_notes goods_receipt_notes_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes
    ADD CONSTRAINT goods_receipt_notes_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE RESTRICT;


--
-- Name: goods_receipt_notes goods_receipt_notes_received_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes
    ADD CONSTRAINT goods_receipt_notes_received_by_foreign FOREIGN KEY (received_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: goods_receipt_notes goods_receipt_notes_vendor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.goods_receipt_notes
    ADD CONSTRAINT goods_receipt_notes_vendor_id_foreign FOREIGN KEY (vendor_id) REFERENCES public.vendors(id) ON DELETE RESTRICT;


--
-- Name: grn_items grn_items_goods_receipt_note_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.grn_items
    ADD CONSTRAINT grn_items_goods_receipt_note_id_foreign FOREIGN KEY (goods_receipt_note_id) REFERENCES public.goods_receipt_notes(id) ON DELETE CASCADE;


--
-- Name: grn_items grn_items_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.grn_items
    ADD CONSTRAINT grn_items_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: grn_items grn_items_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.grn_items
    ADD CONSTRAINT grn_items_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.warehouse_locations(id) ON DELETE RESTRICT;


--
-- Name: grn_items grn_items_purchase_order_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.grn_items
    ADD CONSTRAINT grn_items_purchase_order_item_id_foreign FOREIGN KEY (purchase_order_item_id) REFERENCES public.purchase_order_items(id) ON DELETE RESTRICT;


--
-- Name: inspection_measurements inspection_measurements_inspection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_measurements
    ADD CONSTRAINT inspection_measurements_inspection_id_foreign FOREIGN KEY (inspection_id) REFERENCES public.inspections(id) ON DELETE CASCADE;


--
-- Name: inspection_measurements inspection_measurements_inspection_spec_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_measurements
    ADD CONSTRAINT inspection_measurements_inspection_spec_item_id_foreign FOREIGN KEY (inspection_spec_item_id) REFERENCES public.inspection_spec_items(id) ON DELETE SET NULL;


--
-- Name: inspection_spec_items inspection_spec_items_inspection_spec_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_spec_items
    ADD CONSTRAINT inspection_spec_items_inspection_spec_id_foreign FOREIGN KEY (inspection_spec_id) REFERENCES public.inspection_specs(id) ON DELETE CASCADE;


--
-- Name: inspection_specs inspection_specs_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_specs
    ADD CONSTRAINT inspection_specs_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: inspection_specs inspection_specs_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspection_specs
    ADD CONSTRAINT inspection_specs_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: inspections inspections_inspection_spec_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspections
    ADD CONSTRAINT inspections_inspection_spec_id_foreign FOREIGN KEY (inspection_spec_id) REFERENCES public.inspection_specs(id) ON DELETE SET NULL;


--
-- Name: inspections inspections_inspector_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspections
    ADD CONSTRAINT inspections_inspector_id_foreign FOREIGN KEY (inspector_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inspections inspections_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.inspections
    ADD CONSTRAINT inspections_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: invoice_items invoice_items_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: invoice_items invoice_items_revenue_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_revenue_account_id_foreign FOREIGN KEY (revenue_account_id) REFERENCES public.accounts(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: item_categories item_categories_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.item_categories
    ADD CONSTRAINT item_categories_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.item_categories(id) ON DELETE SET NULL;


--
-- Name: items items_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.item_categories(id) ON DELETE RESTRICT;


--
-- Name: journal_entries journal_entries_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: journal_entries journal_entries_posted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_posted_by_foreign FOREIGN KEY (posted_by) REFERENCES public.users(id);


--
-- Name: journal_entries journal_entries_reversed_by_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entries
    ADD CONSTRAINT journal_entries_reversed_by_entry_id_foreign FOREIGN KEY (reversed_by_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: journal_entry_lines journal_entry_lines_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entry_lines
    ADD CONSTRAINT journal_entry_lines_account_id_foreign FOREIGN KEY (account_id) REFERENCES public.accounts(id);


--
-- Name: journal_entry_lines journal_entry_lines_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.journal_entry_lines
    ADD CONSTRAINT journal_entry_lines_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE CASCADE;


--
-- Name: leave_requests leave_requests_dept_approver_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_dept_approver_id_foreign FOREIGN KEY (dept_approver_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: leave_requests leave_requests_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: leave_requests leave_requests_hr_approver_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_hr_approver_id_foreign FOREIGN KEY (hr_approver_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: leave_requests leave_requests_leave_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.leave_requests
    ADD CONSTRAINT leave_requests_leave_type_id_foreign FOREIGN KEY (leave_type_id) REFERENCES public.leave_types(id);


--
-- Name: loan_payments loan_payments_loan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.loan_payments
    ADD CONSTRAINT loan_payments_loan_id_foreign FOREIGN KEY (loan_id) REFERENCES public.employee_loans(id) ON DELETE CASCADE;


--
-- Name: machine_downtimes machine_downtimes_machine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machine_downtimes
    ADD CONSTRAINT machine_downtimes_machine_id_foreign FOREIGN KEY (machine_id) REFERENCES public.machines(id) ON DELETE RESTRICT;


--
-- Name: machine_downtimes machine_downtimes_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machine_downtimes
    ADD CONSTRAINT machine_downtimes_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE SET NULL;


--
-- Name: machines machines_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE SET NULL;


--
-- Name: machines machines_current_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_current_work_order_id_foreign FOREIGN KEY (current_work_order_id) REFERENCES public.work_orders(id) ON DELETE SET NULL;


--
-- Name: maintenance_logs maintenance_logs_logged_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_logged_by_foreign FOREIGN KEY (logged_by) REFERENCES public.users(id);


--
-- Name: maintenance_logs maintenance_logs_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.maintenance_work_orders(id) ON DELETE CASCADE;


--
-- Name: maintenance_work_orders maintenance_work_orders_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_work_orders
    ADD CONSTRAINT maintenance_work_orders_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: maintenance_work_orders maintenance_work_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_work_orders
    ADD CONSTRAINT maintenance_work_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: maintenance_work_orders maintenance_work_orders_schedule_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.maintenance_work_orders
    ADD CONSTRAINT maintenance_work_orders_schedule_id_foreign FOREIGN KEY (schedule_id) REFERENCES public.maintenance_schedules(id) ON DELETE SET NULL;


--
-- Name: material_issue_slip_items material_issue_slip_items_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slip_items
    ADD CONSTRAINT material_issue_slip_items_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: material_issue_slip_items material_issue_slip_items_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slip_items
    ADD CONSTRAINT material_issue_slip_items_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.warehouse_locations(id) ON DELETE RESTRICT;


--
-- Name: material_issue_slip_items material_issue_slip_items_material_issue_slip_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slip_items
    ADD CONSTRAINT material_issue_slip_items_material_issue_slip_id_foreign FOREIGN KEY (material_issue_slip_id) REFERENCES public.material_issue_slips(id) ON DELETE CASCADE;


--
-- Name: material_issue_slips material_issue_slips_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slips
    ADD CONSTRAINT material_issue_slips_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: material_issue_slips material_issue_slips_issued_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_issue_slips
    ADD CONSTRAINT material_issue_slips_issued_by_foreign FOREIGN KEY (issued_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: material_reservations material_reservations_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_reservations
    ADD CONSTRAINT material_reservations_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: material_reservations material_reservations_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.material_reservations
    ADD CONSTRAINT material_reservations_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.warehouse_locations(id) ON DELETE SET NULL;


--
-- Name: mold_history mold_history_mold_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mold_history
    ADD CONSTRAINT mold_history_mold_id_foreign FOREIGN KEY (mold_id) REFERENCES public.molds(id) ON DELETE CASCADE;


--
-- Name: mold_machine_compatibility mold_machine_compatibility_machine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mold_machine_compatibility
    ADD CONSTRAINT mold_machine_compatibility_machine_id_foreign FOREIGN KEY (machine_id) REFERENCES public.machines(id) ON DELETE CASCADE;


--
-- Name: mold_machine_compatibility mold_machine_compatibility_mold_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mold_machine_compatibility
    ADD CONSTRAINT mold_machine_compatibility_mold_id_foreign FOREIGN KEY (mold_id) REFERENCES public.molds(id) ON DELETE CASCADE;


--
-- Name: molds molds_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.molds
    ADD CONSTRAINT molds_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: mrp_plans mrp_plans_generated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mrp_plans
    ADD CONSTRAINT mrp_plans_generated_by_foreign FOREIGN KEY (generated_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: mrp_plans mrp_plans_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.mrp_plans
    ADD CONSTRAINT mrp_plans_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE CASCADE;


--
-- Name: ncr_actions ncr_actions_ncr_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.ncr_actions
    ADD CONSTRAINT ncr_actions_ncr_id_foreign FOREIGN KEY (ncr_id) REFERENCES public.non_conformance_reports(id) ON DELETE CASCADE;


--
-- Name: ncr_actions ncr_actions_performed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.ncr_actions
    ADD CONSTRAINT ncr_actions_performed_by_foreign FOREIGN KEY (performed_by) REFERENCES public.users(id);


--
-- Name: non_conformance_reports non_conformance_reports_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: non_conformance_reports non_conformance_reports_closed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: non_conformance_reports non_conformance_reports_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: non_conformance_reports non_conformance_reports_inspection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_inspection_id_foreign FOREIGN KEY (inspection_id) REFERENCES public.inspections(id) ON DELETE SET NULL;


--
-- Name: non_conformance_reports non_conformance_reports_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: non_conformance_reports non_conformance_reports_replacement_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.non_conformance_reports
    ADD CONSTRAINT non_conformance_reports_replacement_work_order_id_foreign FOREIGN KEY (replacement_work_order_id) REFERENCES public.work_orders(id) ON DELETE SET NULL;


--
-- Name: notification_preferences notification_preferences_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: overtime_requests overtime_requests_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.overtime_requests
    ADD CONSTRAINT overtime_requests_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: overtime_requests overtime_requests_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.overtime_requests
    ADD CONSTRAINT overtime_requests_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- Name: password_history password_history_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.password_history
    ADD CONSTRAINT password_history_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: payroll_adjustments payroll_adjustments_applied_to_payroll_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments
    ADD CONSTRAINT payroll_adjustments_applied_to_payroll_id_foreign FOREIGN KEY (applied_to_payroll_id) REFERENCES public.payrolls(id);


--
-- Name: payroll_adjustments payroll_adjustments_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments
    ADD CONSTRAINT payroll_adjustments_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: payroll_adjustments payroll_adjustments_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments
    ADD CONSTRAINT payroll_adjustments_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id);


--
-- Name: payroll_adjustments payroll_adjustments_original_payroll_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments
    ADD CONSTRAINT payroll_adjustments_original_payroll_id_foreign FOREIGN KEY (original_payroll_id) REFERENCES public.payrolls(id);


--
-- Name: payroll_adjustments payroll_adjustments_payroll_period_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_adjustments
    ADD CONSTRAINT payroll_adjustments_payroll_period_id_foreign FOREIGN KEY (payroll_period_id) REFERENCES public.payroll_periods(id);


--
-- Name: payroll_deduction_details payroll_deduction_details_payroll_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_deduction_details
    ADD CONSTRAINT payroll_deduction_details_payroll_id_foreign FOREIGN KEY (payroll_id) REFERENCES public.payrolls(id) ON DELETE CASCADE;


--
-- Name: payroll_periods payroll_periods_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: payroll_periods payroll_periods_journal_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_journal_entry_id_foreign FOREIGN KEY (journal_entry_id) REFERENCES public.journal_entries(id) ON DELETE SET NULL;


--
-- Name: payrolls payrolls_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payrolls
    ADD CONSTRAINT payrolls_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id);


--
-- Name: payrolls payrolls_payroll_period_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.payrolls
    ADD CONSTRAINT payrolls_payroll_period_id_foreign FOREIGN KEY (payroll_period_id) REFERENCES public.payroll_periods(id) ON DELETE CASCADE;


--
-- Name: positions positions_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.positions
    ADD CONSTRAINT positions_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id);


--
-- Name: product_price_agreements product_price_agreements_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.product_price_agreements
    ADD CONSTRAINT product_price_agreements_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: product_price_agreements product_price_agreements_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.product_price_agreements
    ADD CONSTRAINT product_price_agreements_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: production_schedules production_schedules_confirmed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.production_schedules
    ADD CONSTRAINT production_schedules_confirmed_by_foreign FOREIGN KEY (confirmed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: production_schedules production_schedules_machine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.production_schedules
    ADD CONSTRAINT production_schedules_machine_id_foreign FOREIGN KEY (machine_id) REFERENCES public.machines(id) ON DELETE RESTRICT;


--
-- Name: production_schedules production_schedules_mold_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.production_schedules
    ADD CONSTRAINT production_schedules_mold_id_foreign FOREIGN KEY (mold_id) REFERENCES public.molds(id) ON DELETE RESTRICT;


--
-- Name: production_schedules production_schedules_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.production_schedules
    ADD CONSTRAINT production_schedules_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: purchase_order_items purchase_order_items_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_purchase_request_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_request_item_id_foreign FOREIGN KEY (purchase_request_item_id) REFERENCES public.purchase_request_items(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_purchase_request_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_purchase_request_id_foreign FOREIGN KEY (purchase_request_id) REFERENCES public.purchase_requests(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_vendor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_vendor_id_foreign FOREIGN KEY (vendor_id) REFERENCES public.vendors(id) ON DELETE RESTRICT;


--
-- Name: purchase_request_items purchase_request_items_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_request_items
    ADD CONSTRAINT purchase_request_items_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE SET NULL;


--
-- Name: purchase_request_items purchase_request_items_purchase_request_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_request_items
    ADD CONSTRAINT purchase_request_items_purchase_request_id_foreign FOREIGN KEY (purchase_request_id) REFERENCES public.purchase_requests(id) ON DELETE CASCADE;


--
-- Name: purchase_requests purchase_requests_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_requests
    ADD CONSTRAINT purchase_requests_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: purchase_requests purchase_requests_mrp_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_requests
    ADD CONSTRAINT purchase_requests_mrp_plan_id_foreign FOREIGN KEY (mrp_plan_id) REFERENCES public.mrp_plans(id) ON DELETE SET NULL;


--
-- Name: purchase_requests purchase_requests_requested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.purchase_requests
    ADD CONSTRAINT purchase_requests_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: role_permissions role_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: sales_order_items sales_order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: sales_order_items sales_order_items_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE CASCADE;


--
-- Name: sales_orders sales_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: sales_orders sales_orders_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: sales_orders sales_orders_mrp_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_mrp_plan_id_foreign FOREIGN KEY (mrp_plan_id) REFERENCES public.mrp_plans(id) ON DELETE SET NULL;


--
-- Name: shipment_documents shipment_documents_shipment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipment_documents
    ADD CONSTRAINT shipment_documents_shipment_id_foreign FOREIGN KEY (shipment_id) REFERENCES public.shipments(id) ON DELETE CASCADE;


--
-- Name: shipment_documents shipment_documents_uploaded_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipment_documents
    ADD CONSTRAINT shipment_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES public.users(id);


--
-- Name: shipments shipments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipments
    ADD CONSTRAINT shipments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: shipments shipments_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.shipments
    ADD CONSTRAINT shipments_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: spare_part_usage spare_part_usage_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.spare_part_usage
    ADD CONSTRAINT spare_part_usage_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id);


--
-- Name: spare_part_usage spare_part_usage_stock_movement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.spare_part_usage
    ADD CONSTRAINT spare_part_usage_stock_movement_id_foreign FOREIGN KEY (stock_movement_id) REFERENCES public.stock_movements(id) ON DELETE SET NULL;


--
-- Name: spare_part_usage spare_part_usage_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.spare_part_usage
    ADD CONSTRAINT spare_part_usage_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.maintenance_work_orders(id) ON DELETE CASCADE;


--
-- Name: stock_levels stock_levels_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_levels
    ADD CONSTRAINT stock_levels_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: stock_levels stock_levels_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_levels
    ADD CONSTRAINT stock_levels_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.warehouse_locations(id) ON DELETE RESTRICT;


--
-- Name: stock_movements stock_movements_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: stock_movements stock_movements_from_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_from_location_id_foreign FOREIGN KEY (from_location_id) REFERENCES public.warehouse_locations(id) ON DELETE SET NULL;


--
-- Name: stock_movements stock_movements_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: stock_movements stock_movements_to_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.stock_movements
    ADD CONSTRAINT stock_movements_to_location_id_foreign FOREIGN KEY (to_location_id) REFERENCES public.warehouse_locations(id) ON DELETE SET NULL;


--
-- Name: thirteenth_month_accruals thirteenth_month_accruals_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.thirteenth_month_accruals
    ADD CONSTRAINT thirteenth_month_accruals_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id);


--
-- Name: thirteenth_month_accruals thirteenth_month_accruals_payroll_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.thirteenth_month_accruals
    ADD CONSTRAINT thirteenth_month_accruals_payroll_id_foreign FOREIGN KEY (payroll_id) REFERENCES public.payrolls(id);


--
-- Name: users users_employee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: users users_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: vehicles vehicles_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE SET NULL;


--
-- Name: warehouse_locations warehouse_locations_zone_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_locations
    ADD CONSTRAINT warehouse_locations_zone_id_foreign FOREIGN KEY (zone_id) REFERENCES public.warehouse_zones(id) ON DELETE CASCADE;


--
-- Name: warehouse_zones warehouse_zones_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.warehouse_zones
    ADD CONSTRAINT warehouse_zones_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: work_order_defects work_order_defects_defect_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_defects
    ADD CONSTRAINT work_order_defects_defect_type_id_foreign FOREIGN KEY (defect_type_id) REFERENCES public.defect_types(id) ON DELETE RESTRICT;


--
-- Name: work_order_defects work_order_defects_output_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_defects
    ADD CONSTRAINT work_order_defects_output_id_foreign FOREIGN KEY (output_id) REFERENCES public.work_order_outputs(id) ON DELETE CASCADE;


--
-- Name: work_order_materials work_order_materials_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_materials
    ADD CONSTRAINT work_order_materials_item_id_foreign FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE RESTRICT;


--
-- Name: work_order_materials work_order_materials_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_materials
    ADD CONSTRAINT work_order_materials_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: work_order_outputs work_order_outputs_recorded_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_outputs
    ADD CONSTRAINT work_order_outputs_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: work_order_outputs work_order_outputs_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_order_outputs
    ADD CONSTRAINT work_order_outputs_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: work_orders work_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: work_orders work_orders_machine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_machine_id_foreign FOREIGN KEY (machine_id) REFERENCES public.machines(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_mold_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_mold_id_foreign FOREIGN KEY (mold_id) REFERENCES public.molds(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_mrp_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_mrp_plan_id_foreign FOREIGN KEY (mrp_plan_id) REFERENCES public.mrp_plans(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_parent_wo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_parent_wo_id_foreign FOREIGN KEY (parent_wo_id) REFERENCES public.work_orders(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: work_orders work_orders_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: ogami
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict CkXjfroXVeXfOIu69qCIrXx6XcEhWYliHHhP5tYH8fW7U80zTv2gHERApsn7OgC

