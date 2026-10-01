# Supply Chain — Shipments, Fleet, Deliveries, Driver Proof

## What is this?

Supply Chain moves goods in and out of the plant:
**inbound import shipments** (ImpEx + customs papers),
the **truck fleet**, **outbound customer deliveries**,
and the **driver's proof** that the customer received them.

## Before you start

1. Go to `/sign-in`.
2. You will use two logins (password for all: `password`):
   - `impex@ogami.test` — office side (shipments, fleet, deliveries).
   - `driver@ogami.test` — driver phone side (my deliveries + photo).

## Part A — Inbound shipments (`/supply-chain/shipments`)

1. Log in as `impex@ogami.test`.
2. Go to `/supply-chain/shipments`.
3. Click **New shipment**.
4. On "New shipment" (`/supply-chain/shipments/create`):
   pick the **purchase order**, vessel/flight, origin port,
   expected arrival date.
5. Click **Create shipment** (busy text: "Creating…").
6. Open the shipment (`/supply-chain/shipments/<id>`).
7. Move it along with the status button and confirm.
8. Customs paperwork lives in the shipment's documents panel:
   attach files such as bill of lading / import permit.

## Part B — Fleet: trucks and vans (`/supply-chain/fleet`)

1. As `impex@ogami.test`, go to `/supply-chain/fleet`.
2. You see every vehicle: plate number, type, status.
3. To add one, click **New vehicle**.
4. Fill plate number, name, vehicle type.
5. Click **Save vehicle**.
6. Archive/restore buttons retire or bring back vehicles.

## Part C — Outbound deliveries (`/supply-chain/deliveries`)

1. As `impex@ogami.test`, go to `/supply-chain/deliveries`.
2. Click **New delivery**.
3. On "New delivery" (`/supply-chain/deliveries/create`):
   pick the **sales order** being delivered, items + quantities.
4. Click **Create delivery** (busy text: "Creating…").
5. Open the delivery (`/supply-chain/deliveries/<id>`).
6. Click **Assign driver & vehicle** (later edits read **Reassign**),
   pick the van and the driver, save.
7. The driver now sees this delivery on his phone (Part D).
8. When the signed receipt photo is uploaded, the
   **Proof of delivery** panel turns complete.
9. Click **Confirm** to confirm the delivery
   (needs the proof attached first; without it the button stays disabled).
10. Confirming can auto-create the draft customer invoice for Finance.

## Part D — Driver phone flow: my deliveries + photo proof

Use a phone-sized browser window for the full effect.

1. Log in as `driver@ogami.test` (password: `password`).
2. Go to `/driver` ("My Deliveries").
3. You see ONLY this driver's assigned runs, with statuses.
4. Tap a delivery to open `/driver/<id>`.
5. Advance its status with the on-screen confirm sheet.
6. After delivery, tap the photo step to open `/driver/<id>/photo`.
7. Photograph the signed receipt (or goods + customer name visible).
8. Tap **Upload photo** (warns if the file exceeds 8 MB).
9. Back in the office (`impex@ogami.test`), the delivery's
   **Proof of delivery** panel now shows the photo,
   with Received by / photo / timestamp.

## Part E — End-to-end demo script (5 minutes)

1. `impex@ogami.test`: create a shipment
   (`/supply-chain/shipments/create`) and mark it one step forward.
2. `impex@ogami.test`: open `/supply-chain/fleet`, show the assigned van.
3. `impex@ogami.test`: create a delivery, then
   **Assign driver & vehicle** (driver: Nestor Flores).
4. `driver@ogami.test`: open `/driver`, run the delivery, **Upload photo**.
5. `impex@ogami.test`: reopen the delivery, show the proof, click **Confirm**.

## Who can do what (cheat sheet)

- `impex@ogami.test` — shipments, fleet, create + confirm deliveries.
- `warehouse@ogami.test` — view deliveries (staging: what leaves today).
- `driver@ogami.test` — own runs + photo proof only, nothing else.
