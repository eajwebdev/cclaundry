from html import escape
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

from PIL import Image


OUT = Path("docs/client-workflow-presentation-script.docx")
IMAGE_PATH = Path("docs/client-workflow-diagram.png")


sections = [
    ("title", "Laundry Pickup & Delivery Workflow - Client Presentation Script"),
    ("p", "Use this document together with the workflow diagram. It is written as a short speaking guide for a Google Meet or Microsoft Teams presentation."),
    ("h1", "Quick Opening Script"),
    ("p", "Good day. I will walk you through how the laundry system works from booking up to final release. The diagram is divided into three users: Customer, Admin/Staff, and Driver/Rider. Each user has their own simple workflow, but all actions are connected in one system."),
    ("h1", "Diagram Overview"),
    ("p", "The top row shows the full status flow: Booking, Pickup Confirmed, Collected, Job Order, Production, Ready, Released, and Cancelled. The three columns below show what each user does at every stage."),
    ("image", ""),
    ("h1", "Step-by-Step Explanation"),
    ("h2", "1. Customer Flow"),
    ("bullet", "Customer opens the booking page."),
    ("bullet", "Customer selects branch, laundry service, pickup schedule, address, payment method, and delivery option."),
    ("bullet", "System creates a booking reference number."),
    ("bullet", "Customer can track the booking status and rider location."),
    ("bullet", "Customer receives laundry by delivery or claims it at the branch."),
    ("h2", "2. Admin / Staff Flow"),
    ("bullet", "Admin receives the new online pickup booking."),
    ("bullet", "Admin confirms the request, assigns a rider, or cancels if needed."),
    ("bullet", "Staff receives the collected laundry at the branch."),
    ("bullet", "Staff creates the job order, checks weight, prices services, and records payment."),
    ("bullet", "Laundry is processed through washing, drying, and folding."),
    ("bullet", "Staff marks the job order ready for pickup or ready for delivery."),
    ("bullet", "Order is closed when laundry is released to the customer."),
    ("h2", "3. Driver / Rider Flow"),
    ("bullet", "Rider views assigned or available pickup runs."),
    ("bullet", "Rider accepts or claims a booking."),
    ("bullet", "Rider goes to the customer location using the map/route."),
    ("bullet", "Rider collects laundry and records tag/payment details."),
    ("bullet", "Rider brings laundry back to the branch for processing."),
    ("bullet", "If delivery is selected, rider delivers the finished laundry back to the customer."),
    ("h1", "Meeting Script While Sharing Screen"),
    ("h2", "Before You Start"),
    ("bullet", "Open the diagram image or this Word file before the meeting."),
    ("bullet", "In Google Meet or Microsoft Teams, click Share Screen."),
    ("bullet", "Share the window where the diagram is open."),
    ("bullet", "Zoom in if the client cannot read the text clearly."),
    ("h2", "Script: Start"),
    ("p", "This diagram shows the complete workflow of the laundry pickup and delivery system. We separated it by user so it is easy to understand who does what: the Customer, the Admin or Staff, and the Driver or Rider."),
    ("h2", "Script: Explain Customer"),
    ("p", "First, on the Customer side, the customer books a pickup online. They choose the branch, service, schedule, address, payment method, and whether they want delivery. After submitting, the system gives them a booking reference number. They can use that number to track the status of their laundry."),
    ("h2", "Script: Explain Admin / Staff"),
    ("p", "Next is the Admin or Staff side. The staff receives the online booking, reviews the details, confirms it, and assigns a rider. Once the laundry arrives at the branch, staff creates a job order, checks the final weight and pricing, records payment, and moves the order through washing, drying, and folding."),
    ("h2", "Script: Explain Driver / Rider"),
    ("p", "On the Driver or Rider side, the rider sees available or assigned pickup runs. They accept the run, go to the customer location, collect the laundry, record the bag tag and payment details if needed, and bring the laundry to the branch. If the customer selected delivery, the rider later delivers the finished laundry back."),
    ("h2", "Script: Explain Status Row"),
    ("p", "The status row at the top shows the life cycle of one booking. It starts as a booking, becomes confirmed, then collected, then converted into a job order. After production, it becomes ready, then released or completed. If something goes wrong, it can move to cancelled."),
    ("h2", "Script: Explain Handoffs"),
    ("p", "The important part is the handoff. Customer to Admin is the online booking. Admin to Driver is the assigned pickup. Driver to Admin is the collected laundry returned to the branch. Admin or Driver back to Customer is the final release or delivery."),
    ("h2", "Script: Closing"),
    ("p", "In short, this system keeps the booking, pickup, rider tracking, job order, laundry process, payment, and release connected in one flow. It reduces manual tracking and makes it clear who is responsible at every step."),
    ("h1", "Simple Client Summary"),
    ("p", "Customer books and tracks. Admin manages and processes. Rider collects and delivers. The system connects all three users from pickup request to completed laundry order."),
]


def paragraph(text: str, kind: str = "p") -> str:
    text = escape(text)

    if kind == "title":
        return (
            '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr>'
            '<w:r><w:rPr><w:b/><w:sz w:val="36"/></w:rPr>'
            f"<w:t>{text}</w:t></w:r></w:p>"
        )

    if kind == "h1":
        return (
            '<w:p><w:pPr><w:pStyle w:val="Heading1"/>'
            '<w:spacing w:before="240" w:after="120"/></w:pPr>'
            '<w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr>'
            f"<w:t>{text}</w:t></w:r></w:p>"
        )

    if kind == "h2":
        return (
            '<w:p><w:pPr><w:pStyle w:val="Heading2"/>'
            '<w:spacing w:before="180" w:after="80"/></w:pPr>'
            '<w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr>'
            f"<w:t>{text}</w:t></w:r></w:p>"
        )

    if kind == "bullet":
        return (
            '<w:p><w:pPr><w:ind w:left="360" w:hanging="180"/></w:pPr>'
            f"<w:r><w:t>- {text}</w:t></w:r></w:p>"
        )

    return (
        '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr>'
        '<w:r><w:rPr><w:sz w:val="22"/></w:rPr>'
        f"<w:t>{text}</w:t></w:r></w:p>"
    )


def image_xml() -> str:
    with Image.open(IMAGE_PATH) as image:
        width, height = image.size

    cx = int(6.6 * 914400)
    cy = int(cx * height / width)

    return f"""
    <w:p>
      <w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="180"/></w:pPr>
      <w:r>
        <w:drawing>
          <wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">
            <wp:extent cx="{cx}" cy="{cy}"/>
            <wp:docPr id="1" name="Workflow Diagram"/>
            <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
              <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                  <pic:nvPicPr><pic:cNvPr id="1" name="client-workflow-diagram.png"/><pic:cNvPicPr/></pic:nvPicPr>
                  <pic:blipFill>
                    <a:blip r:embed="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>
                    <a:stretch><a:fillRect/></a:stretch>
                  </pic:blipFill>
                  <pic:spPr>
                    <a:xfrm><a:off x="0" y="0"/><a:ext cx="{cx}" cy="{cy}"/></a:xfrm>
                    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                  </pic:spPr>
                </pic:pic>
              </a:graphicData>
            </a:graphic>
          </wp:inline>
        </w:drawing>
      </w:r>
    </w:p>
    """


body = []
for kind, text in sections:
    body.append(image_xml() if kind == "image" else paragraph(text, kind))

document_xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
    {''.join(body)}
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>
"""

content_types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
"""

rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
"""

document_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/client-workflow-diagram.png"/>
</Relationships>
"""

OUT.parent.mkdir(parents=True, exist_ok=True)

with ZipFile(OUT, "w", ZIP_DEFLATED) as docx:
    docx.writestr("[Content_Types].xml", content_types)
    docx.writestr("_rels/.rels", rels)
    docx.writestr("word/document.xml", document_xml)
    docx.writestr("word/_rels/document.xml.rels", document_rels)
    docx.write(IMAGE_PATH, "word/media/client-workflow-diagram.png")

print(OUT.resolve())
