#!/usr/bin/env python3
"""Build fictional, fillable customer-agreement PDFs for the Ogami thesis demo."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    Flowable,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)


ROOT = Path(__file__).resolve().parent
OUTPUT_TEMPLATE = ROOT / "customer-supply-agreement-template.pdf"
OUTPUT_SAMPLE = ROOT / "sample-customer-supply-agreement.pdf"

INK = colors.HexColor("#1f1b16")
SECONDARY = colors.HexColor("#4a4239")
MUTED = colors.HexColor("#6b6259")
CLAY = colors.HexColor("#b4542a")
MOSS = colors.HexColor("#3f6d54")
PAPER = colors.HexColor("#fdfcfa")
SURFACE = colors.HexColor("#f7f4ef")
BORDER = colors.HexColor("#d6cec1")
WHITE = colors.white


@dataclass(frozen=True)
class Field:
    name: str
    label: str
    template_value: str = "[MAP THIS FIELD]"
    sample_value: str = ""
    multiline: bool = False


class AcroFormRow(Flowable):
    """A Platypus flowable that places one or more named PDF text fields."""

    def __init__(self, fields: list[Field], box_height: int = 24, row_height: int = 48, gap: int = 12):
        super().__init__()
        self.fields = fields
        self.box_height = box_height
        self.height = row_height
        self.gap = gap

    def wrap(self, avail_width: float, avail_height: float) -> tuple[float, float]:
        self.width = avail_width
        return self.width, self.height

    def draw(self) -> None:
        count = len(self.fields)
        cell_width = (self.width - self.gap * (count - 1)) / count
        label_y = self.height - 10
        box_y = 1
        for index, field in enumerate(self.fields):
            x = index * (cell_width + self.gap)
            self.canv.setFillColor(SECONDARY)
            self.canv.setFont("OgamiSans-Bold", 7.5)
            self.canv.drawString(x, label_y, field.label)
            widget_x, widget_y = self.canv.absolutePosition(x, box_y)
            self.canv.acroForm.textfield(
                name=field.name,
                tooltip=field.label,
                x=widget_x,
                y=widget_y,
                width=cell_width,
                height=self.box_height,
                value=field.sample_value if SAMPLE_MODE else field.template_value,
                fontName="Helvetica",
                fontSize=8,
                textColor=INK,
                fillColor=PAPER,
                borderColor=BORDER,
                borderWidth=0.7,
                borderStyle="solid",
                forceBorder=True,
                fieldFlags="multiline" if field.multiline else "",
                maxlen=2500 if field.multiline else 255,
            )


SAMPLE_MODE = False


def build_styles() -> dict[str, ParagraphStyle]:
    return {
        "title": ParagraphStyle(
            "AgreementTitle", fontName="OgamiSerif", fontSize=24, leading=27,
            textColor=INK, spaceAfter=5,
        ),
        "subtitle": ParagraphStyle(
            "AgreementSubtitle", fontName="OgamiSans", fontSize=9, leading=13,
            textColor=MUTED, spaceAfter=9,
        ),
        "body": ParagraphStyle(
            "AgreementBody", fontName="OgamiSans", fontSize=8.4, leading=12,
            textColor=SECONDARY, spaceAfter=5,
        ),
        "small": ParagraphStyle(
            "AgreementSmall", fontName="OgamiSans", fontSize=7.3, leading=10,
            textColor=MUTED, spaceAfter=3,
        ),
        "section": ParagraphStyle(
            "AgreementSection", fontName="OgamiSans-Bold", fontSize=10.4, leading=13,
            textColor=INK, spaceBefore=6, spaceAfter=5, keepWithNext=True,
        ),
        "subsection": ParagraphStyle(
            "AgreementSubsection", fontName="OgamiSans-Bold", fontSize=8.5, leading=11,
            textColor=MOSS, spaceBefore=4, spaceAfter=2, keepWithNext=True,
        ),
        "table_header": ParagraphStyle(
            "AgreementTableHeader", fontName="OgamiSans-Bold", fontSize=7.2, leading=9,
            textColor=WHITE,
        ),
        "table_cell": ParagraphStyle(
            "AgreementTableCell", fontName="OgamiSans", fontSize=7.2, leading=9.4,
            textColor=SECONDARY,
        ),
        "callout": ParagraphStyle(
            "AgreementCallout", fontName="OgamiSans", fontSize=8.1, leading=11.2,
            textColor=SECONDARY,
        ),
        "center": ParagraphStyle(
            "AgreementCenter", fontName="OgamiSans", fontSize=8, leading=11,
            textColor=SECONDARY, alignment=TA_CENTER,
        ),
    }


def p(text: str, style: ParagraphStyle) -> Paragraph:
    return Paragraph(text, style)


def field(name: str, label: str, *, multiline: bool = False) -> Field:
    samples = {
        "customer_name": "Demo Automotive Components, Inc.",
        "customer_contact_name": "Marissa Santos",
        "customer_contact_role": "Procurement Manager",
        "customer_email": "marissa.santos@example.test",
        "customer_phone": "+63 2 8123 4567",
        "customer_address": "1200 Sample Avenue, Barangay Paseo, Santa Rosa, Laguna 4026, Philippines",
        "delivery_address": "Warehouse Gate B, First Laguna Industrial Park, Biñan, Laguna 4024, Philippines",
        "delivery_map_pin": "14.25, 121.10 (illustrative WGS84 pin; verify before use)",
        "payment_terms": "Net 30 calendar days from accepted invoice; confirmed per Sales Order",
        "allowed_payment_methods": "Bank transfer or company check",
        "shipping_payer": "Buyer",
        "effective_date": "2026-11-10",
        "expiry_date": "2027-11-09",
        "allowed_products_summary": (
            "BB-001 · Wiper Motor Bobbin — minimum 10 / maximum 500 pcs per order\n"
            "BU-001 · Windshield Wiper Bushing — minimum 5 / maximum 300 pcs per order"
        ),
        "warranty_summary": (
            "BB-001 · 12-month standard: 365 days from delivery; report within 30 days; "
            "manufacturing defects and shipping damage; repair or replacement.\n"
            "BU-001 · 6-month limited: 180 days from delivery; report within 14 days; "
            "manufacturing defects; replacement of affected part."
        ),
    }
    return Field(
        name=name,
        label=label,
        sample_value=samples.get(name, ""),
        multiline=multiline,
    )


def form_row(*fields: Field, box_height: int = 24, row_height: int = 48) -> Flowable:
    if SAMPLE_MODE:
        content_width = letter[0] - 1.34 * inch
        cell_width = content_width / len(fields)
        value_style = ParagraphStyle(
            "SampleFieldValue", fontName="OgamiSans", fontSize=8, leading=10.5,
            textColor=INK, splitLongWords=1,
        )
        cells = []
        for item in fields:
            value = escape(item.sample_value).replace("\n", "<br/>") or "—"
            cells.append(p(
                f'<font name="OgamiSans-Bold" size="7.3" color="#4a4239">{escape(item.label)}</font>'
                f'<br/><font name="OgamiSans" size="8" color="#1f1b16">{value}</font>',
                value_style,
            ))
        table = Table(
            [cells],
            colWidths=[cell_width] * len(fields),
            hAlign="LEFT",
        )
        table.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, -1), PAPER),
            ("BOX", (0, 0), (-1, -1), 0.65, BORDER),
            ("INNERGRID", (0, 0), (-1, -1), 0.45, BORDER),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 7),
            ("RIGHTPADDING", (0, 0), (-1, -1), 7),
            ("TOPPADDING", (0, 0), (-1, -1), 6),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
        ]))
        table.spaceAfter = 3
        return table
    return AcroFormRow(list(fields), box_height=box_height, row_height=row_height)


def section(number: int, title: str, body: str, styles: dict[str, ParagraphStyle]) -> list[Flowable]:
    return [
        p(f"{number}. {title}", styles["section"]),
        p(body, styles["body"]),
    ]


def schedule_table(styles: dict[str, ParagraphStyle]) -> Table:
    if SAMPLE_MODE:
        rows = [
            ["Part / product", "Illustrative specification", "Minimum", "Maximum", "Drawing / price"],
            [
                "BB-001 · Wiper Motor Bobbin",
                "12 g Resin D; metal core; poly bag. See approved drawing for dimensions.",
                "10 pcs",
                "500 pcs",
                "BP-BB001-01 · PHP 245 / pc",
            ],
            [
                "BU-001 · Windshield Wiper Bushing",
                "18 g Resin A; 2 g white colorant; poly bag. See approved drawing for dimensions.",
                "5 pcs",
                "300 pcs",
                "BP-BU001-01 · PHP 380 / pc",
            ],
        ]
    else:
        rows = [
            ["Order-level detail", "Recorded on the accepted Sales Order"],
            ["Quantity and unit price", "Confirmed for each order; subject to stock and capacity"],
            ["Per-product order limits", "Record the minimum and maximum quantity for each authorized product and order line"],
            ["Dimensions and materials", "Attach the approved product specification or drawing"],
            ["Custom configuration", "Attach the signed-off blueprint and revision number"],
        ]
    converted = []
    for row_index, row in enumerate(rows):
        style = styles["table_header"] if row_index == 0 else styles["table_cell"]
        converted.append([p(str(value), style) for value in row])
    widths = [1.42 * inch, 1.58 * inch, 0.85 * inch, 0.9 * inch, 1.93 * inch] if SAMPLE_MODE else [2.0 * inch, 4.45 * inch]
    table = Table(converted, colWidths=widths, repeatRows=1, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), MOSS),
        ("BACKGROUND", (0, 1), (-1, -1), PAPER),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [PAPER, SURFACE]),
        ("GRID", (0, 0), (-1, -1), 0.45, BORDER),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    return table


def story(styles: dict[str, ParagraphStyle]) -> list[Flowable]:
    contract_ref = "CSA-DEMO-2026-001" if SAMPLE_MODE else "[ASSIGN UPON ISSUE]"
    items: list[Flowable] = [
        p("CUSTOMER SUPPLY AGREEMENT", styles["title"]),
        p("Framework terms for product access, orders, delivery, quality, payment, and per-product warranty.", styles["subtitle"]),
        Table([[p("AGREEMENT REF.", styles["small"]), p(contract_ref, styles["body"])]], colWidths=[1.2 * inch, 2.1 * inch], style=TableStyle([
            ("BACKGROUND", (0, 0), (-1, -1), SURFACE),
            ("BOX", (0, 0), (-1, -1), 0.6, BORDER),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 8),
            ("TOPPADDING", (0, 0), (-1, -1), 5),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ])),
        Spacer(1, 8),
        p(
            "This agreement records the parties’ general buying and supply terms. Each Sales Order confirms its own items, quantities, price, delivery date, order-level payment terms, and any required downpayment. Product-level specifications and warranty coverage are shown in the schedules and order documents referenced below.",
            styles["body"],
        ),
        p("PARTIES AND AGREEMENT PERIOD", styles["section"]),
        p("Supplier", styles["subsection"]),
        p("Ogami Demo Manufacturing Corporation (fictional demonstration supplier)<br/>88 Sample Commerce Road, Santa Rosa, Laguna, Philippines", styles["body"]),
        p("Customer", styles["subsection"]),
        form_row(field("customer_name", "Customer legal name")),
        form_row(
            field("customer_contact_name", "Authorized contact person"),
            field("customer_contact_role", "Contact role / title"),
        ),
        form_row(
            field("customer_email", "Business email"),
            field("customer_phone", "Business phone"),
        ),
        form_row(field("customer_address", "Billing address", multiline=True), box_height=41, row_height=63),
        form_row(field("delivery_address", "Delivery / receiving address", multiline=True), box_height=41, row_height=63),
        form_row(field("delivery_map_pin", "Required delivery map pin — latitude, longitude")),
        form_row(
            field("effective_date", "Effective date (YYYY-MM-DD)"),
            field("expiry_date", "Expiry date (YYYY-MM-DD)"),
        ),
        Spacer(1, 4),
        PageBreak(),
        p("ORDERING AND COMMERCIAL TERMS", styles["title"]),
        p("Order-level terms keep item quantities, price, payment, and delivery together on each order.", styles["subtitle"]),
        *section(
            1,
            "Scope and authorized products",
            "The customer may request only products authorized for its account. The current customer-record catalog access is summarized here. A requested item is not accepted until the supplier confirms it in a Sales Order.",
            styles,
        ),
        form_row(field("allowed_products_summary", "Products currently authorized for this customer", multiline=True), box_height=46, row_height=68),
        p("The agreement does not reserve inventory. Availability and production capacity are confirmed for each order.", styles["small"]),
        *section(
            2,
            "Sales Orders and product specifications",
            "The customer submits a purchase order or portal request. The supplier reviews product eligibility, quantity, current stock, manufacturing capacity, requested delivery date, and any customization before accepting. A Sales Order is binding only after the supplier confirms it in writing or through the portal.",
            styles,
        ),
        p("For every accepted order, the Sales Order records the part number, quantity, unit price, applicable taxes, total, delivery date, and order-level payment terms. Product schedules may set a minimum and maximum quantity per order line; all requested quantities remain subject to confirmed stock and production capacity.", styles["body"]),
        p("Customized products require an approved blueprint or technical drawing that identifies material, dimensions, revision, tolerances, and acceptance criteria. The approved order documents control those details; a general catalog description alone is not a manufacturing specification.", styles["body"]),
        schedule_table(styles),
        Spacer(1, 8),
        *section(
            3,
            "Payment, proof, and downpayment",
            "The payment term below is the customer’s default for a new order. The accepted Sales Order may confirm or override it for that order total; it never applies to only one line item unless the order expressly says so. Payment is due against the verified invoice and the due date recorded for that order.",
            styles,
        ),
        form_row(field("payment_terms", "Customer default payment terms")),
        form_row(
            field("allowed_payment_methods", "Payment methods accepted under this agreement"),
            field("shipping_payer", "Freight paid by"),
        ),
        p("When bank transfer is selected, the customer submits the transfer reference or payment proof through the portal. Payment is treated as received after the supplier verifies settlement. A downpayment is optional and applies only when its amount and due date are stated on the accepted Sales Order.", styles["body"]),
        PageBreak(),
        p("DELIVERY, QUALITY AND WARRANTY", styles["title"]),
        p("Warranty options belong to a customer and product pair. The terms below are summarized from the customer record and should match the approved agreement schedule.", styles["subtitle"]),
        *section(
            4,
            "Delivery and receiving",
            "The saved receiving address and map pin identify the intended delivery point. Before dispatch, the supplier confirms the delivery window, route, vehicle capacity, driver availability, and any site-access requirements. Driver identity, credentials, plate number, vehicle, and load weight are recorded on the shipment record.",
            styles,
        ),
        p("The customer provides a receiving contact and safe unloading access. The receiving representative records shortages or visible transport damage on the delivery record and attaches supporting photographs when applicable.", styles["body"]),
        *section(
            5,
            "Product-specific warranty choices",
            "The warranty summary is maintained per product and may offer more than one choice. The applicable choice is selected and saved on each Sales Order. No product inherits another product’s warranty. If a product has no configured warranty choice, coverage must be agreed in writing before order acceptance.",
            styles,
        ),
        form_row(field("warranty_summary", "Warranty terms by product and available option", multiline=True), box_height=112, row_height=134),
        p("An order records the selected warranty option, duration, start event, report window, covered issues, remedies, and any additional terms so the agreed coverage remains attached to that order.", styles["body"]),
        *section(
            6,
            "Inspection, defects, and quality records",
            "The customer inspects delivered items within the receiving window stated on the Sales Order. Defect reports identify the part, order, quantity affected, defect level, description, and requested remedy. Attach clear photographs and inspection evidence. Critical, major, and minor classifications guide review priority; the accepted order or quality disposition determines the remedy.",
            styles,
        ),
        p("The supplier records inspection results and may request return, replacement, repair, or credit evidence before closing a claim. Warranty remedies are limited to the selected product-specific option and do not apply to damage caused by misuse, unauthorized modification, or normal wear unless the agreed option says otherwise.", styles["body"]),
        p("Supplier invoices and any supporting quality or payment documents are attached to the relevant transaction in the portal. Each uploaded file remains a draft until reviewed and issued by authorized staff.", styles["body"]),
        Spacer(1, 7),
        Table([[p("DEMO DATA NOTE", styles["table_header"]), p("This sample uses fictional parties, product numbers, locations, specifications, prices, and warranty terms. Replace them with reviewed and approved commercial terms before any real use.", styles["callout"])]], colWidths=[1.2 * inch, 5.45 * inch], style=TableStyle([
            ("BACKGROUND", (0, 0), (0, 0), MOSS),
            ("BACKGROUND", (1, 0), (1, 0), SURFACE),
            ("BOX", (0, 0), (-1, -1), 0.55, BORDER),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 8),
            ("RIGHTPADDING", (0, 0), (-1, -1), 8),
            ("TOPPADDING", (0, 0), (-1, -1), 7),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ])),
        PageBreak(),
        p("GENERAL TERMS AND SIGNATURES", styles["title"]),
        p("Review the completed agreement and the referenced order schedules before issuing it to the customer portal.", styles["subtitle"]),
        *section(
            7,
            "Agreement period and changes",
            "This agreement begins on its effective date and continues until its expiry date unless the parties extend or end it in writing. Ending this agreement does not cancel an accepted Sales Order, unpaid invoice, open quality claim, or warranty obligation already attached to an order. Amendments must identify the changed term and be accepted by authorized representatives of both parties.",
            styles,
        ),
        *section(
            8,
            "Order of precedence and records",
            "For a particular transaction, a mutually accepted written change controls only the term it expressly changes; otherwise, the accepted Sales Order controls item quantity, price, delivery schedule, order-level payment term, optional downpayment, and selected warranty. This framework controls the remaining general terms. Product specifications, blueprints, invoice proofs, shipment evidence, and quality photographs are retained with their related transaction records.",
            styles,
        ),
        *section(
            9,
            "Electronic signature",
            "After staff review, the agreement is issued to the customer portal. An authorized customer signatory may review the issued PDF and sign through the portal using the account’s recorded name. The portal stores the signed name, account, time, and signed document version. The parties should verify the exact PDF version before signing.",
            styles,
        ),
        Spacer(1, 6),
        p("SIGNATURES", styles["section"]),
        p("The signatures below acknowledge the reviewed version of this agreement and its schedules.", styles["body"]),
        Spacer(1, 14),
        Table([
            [p("SUPPLIER", styles["table_header"]), p("CUSTOMER", styles["table_header"])],
            [p("Ogami Demo Manufacturing Corporation", styles["body"]), p("Demo Automotive Components, Inc." if SAMPLE_MODE else "Customer legal name shown above", styles["body"])],
            [p("Authorized representative: ______________________________", styles["small"]), p("Authorized representative: ______________________________", styles["small"])],
            [p("Title: _________________________________________________", styles["small"]), p("Title: __________________________________________________", styles["small"])],
            [p("Date: __________________________________________________", styles["small"]), p("Date: __________________________________________________", styles["small"])],
        ], colWidths=[3.3 * inch, 3.35 * inch], style=TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), MOSS),
            ("BACKGROUND", (0, 1), (-1, -1), PAPER),
            ("GRID", (0, 0), (-1, -1), 0.45, BORDER),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 9),
            ("RIGHTPADDING", (0, 0), (-1, -1), 9),
            ("TOPPADDING", (0, 0), (-1, -1), 7),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ])),
        Spacer(1, 12),
        p("DEMO SPECIMEN · NOT FOR SIGNATURE · NOT A LEGAL FORM", styles["center"]),
    ]
    return items


def draw_page(canvas, doc) -> None:
    canvas.saveState()
    page_width, page_height = letter
    canvas.setFillColor(MOSS)
    canvas.rect(0, page_height - 32, page_width, 32, fill=1, stroke=0)
    canvas.setFillColor(WHITE)
    canvas.setFont("OgamiSans-Bold", 8)
    canvas.drawString(doc.leftMargin, page_height - 20, "OGAMI ERP  /  CUSTOMER AGREEMENTS")
    canvas.setFont("OgamiSans", 7)
    canvas.drawRightString(page_width - doc.rightMargin, page_height - 20, "THESIS DEMO · FICTIONAL PARTIES")
    canvas.setStrokeColor(BORDER)
    canvas.setLineWidth(0.6)
    canvas.line(doc.leftMargin, 43, page_width - doc.rightMargin, 43)
    canvas.setFillColor(MUTED)
    canvas.setFont("OgamiSans", 7)
    canvas.drawString(doc.leftMargin, 30, "DEMO SPECIMEN — illustrative only; review and replace before real use")
    canvas.drawRightString(page_width - doc.rightMargin, 30, f"Page {doc.page}")
    canvas.restoreState()


def register_fonts() -> None:
    font_root = Path("/usr/share/fonts/truetype/dejavu")
    pdfmetrics.registerFont(TTFont("OgamiSans", str(font_root / "DejaVuSans.ttf")))
    pdfmetrics.registerFont(TTFont("OgamiSans-Bold", str(font_root / "DejaVuSans-Bold.ttf")))
    pdfmetrics.registerFont(TTFont("OgamiSerif", str(font_root / "DejaVuSerif.ttf")))


def build(output: Path, sample: bool) -> None:
    global SAMPLE_MODE
    SAMPLE_MODE = sample
    doc = SimpleDocTemplate(
        str(output),
        pagesize=letter,
        leftMargin=0.67 * inch,
        rightMargin=0.67 * inch,
        topMargin=0.62 * inch,
        bottomMargin=0.72 * inch,
        title="Customer Supply Agreement — Demo Specimen",
        author="Ogami ERP Thesis Demonstration",
        subject="Fictional, fillable customer supply agreement sample",
        pageCompression=1,
    )
    doc.build(story(build_styles()), onFirstPage=draw_page, onLaterPages=draw_page)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=ROOT)
    options = parser.parse_args()
    options.output_dir.mkdir(parents=True, exist_ok=True)
    register_fonts()
    build(options.output_dir / OUTPUT_TEMPLATE.name, sample=False)
    build(options.output_dir / OUTPUT_SAMPLE.name, sample=True)
    print(f"Generated {options.output_dir / OUTPUT_TEMPLATE.name}")
    print(f"Generated {options.output_dir / OUTPUT_SAMPLE.name}")


if __name__ == "__main__":
    main()
