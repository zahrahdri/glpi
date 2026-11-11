# Project reports – GLPI 11 plugin

Adds **Tools › Project reports** with four tabs, drawn from live GLPI data:

| Tab | Shows |
| --- | --- |
| Team requests | Requests by project, priority, status, month, requesting team and category; open requests with age and SLA; click a bar to filter by that project |
| Budget & cost | Budget vs allocated vs pipeline, where the money went, scorecard (% used), spend to date and forecast vs budget |
| Forecast & renewals | Next-12-month outlook vs remaining budget, forecast per project, warranty renewals, hardware refresh, all BOQ orders |
| Rack view | Floor plan, rack drawings coloured by project / warranty / device type, rack capacity (U, power, weight), device card with parts and warranty |

It creates no report tables. Its only write: when the *Project-Based Capacity Planning Request* form (or any form with a Project question) creates a ticket, the ticket is linked to the chosen project (Ticket › Projects tab), so the request appears in the reports. Charts are drawn with Apache ECharts 5.6, bundled in `public/lib`, so no internet is needed.

## Install (offline server, same as the Order plugin)

```bash
# copy projectreports-1.3.0.tar.bz2 to the server's /tmp, then:
cd /var/lib/docker/volumes/glpi-docker_glpi_data/_data/marketplace/
tar -xjf /tmp/projectreports-1.3.0.tar.bz2
chown -R www-data:www-data projectreports     # same owner as the order folder: ls -ln to check
```

In GLPI: **Setup › Plugins** › *Project reports* › **Install**, then **Enable**. Open **Tools › Project reports**.

## Who sees what (native GLPI rights, no new profile settings)

| Tab | Needs |
| --- | --- |
| Team requests | Projects **See all** and Tickets **See all tickets** |
| Budget & cost, Forecast & renewals | Projects **See all** and Budgets read |
| Rack view | Data centers |

Profiles without any of these rights do not get the menu entry. Note: the reports count data from all entities.

## What GLPI must contain

- Project ↔ budget: Project › **Costs** tab › a line whose **Budget** is the project budget (cost 0 is fine).
- Budget value, start and end date (Management › Budgets).
- Ticket ↔ project: ticket › **Projects** tab. Requesting team = ticket requester **group**.
- Asset Financial information: value, budget, warranty start and duration.
- Racks: devices placed in racks; models with Required units, Power consumption and Weight.
- BOQ figures need the **Orders management** plugin. If a table is missing, the tab says which one.

## How the numbers are calculated

- **Allocated** = BOQ orders Validated / Being delivered / Delivered / Paid (tax-free lines + shipping) + assets on the budget not created from an order + project cost lines.
- **Pipeline** = BOQ orders in Draft or Waiting for approval; canceled orders are ignored.
- **Forecast at end** = allocated + the larger of (pipeline) and (monthly BOQ spend since budget start × months left).
- **Renewal** = warranty extension value, else 10 % of asset value. **Refresh** = in service + 5 years. Change `0.10` / `5 YEAR` in `inc/data.class.php`.

## Uninstall

Setup › Plugins › Disable › Uninstall, then delete the `projectreports` folder.

## Price lists / LOP (Management › Price lists (LOP))

*Tools › Import BOQ was removed in 1.18.0. Standard kits moved to Management › Standard kits.*


The supplier's list of prices, used to price the standard kits and the BOQs of the requests, so all requests are
estimated with the same prices. Download the **LOM template** (`templates/LOM_TEMPLATE.xlsx`) and fill in:
**Part Number**, **Description**, **Qty per device**, **Number of devices**, **Qty** (calculated) and **Spare Part number**.
The LOM has no prices, device or model columns (since 1.20.0). **Unit Price** is optional on import: a file without it
keeps the prices already saved for those parts (a new part gets 0 until its price is entered). Check the preview
(new parts, changed prices with %), then save. A Unit Price column still works, with the same prices as before.
Device, when present in the file, works as before; a file without it keeps the saved device of each part.
The LOP is kept in its own table: the LOP never changes imported BOQs or the Orders catalog prices. Tick "Re-price the draft automatic BOQs" to put the new prices on every
open estimate (draft `LOP-...` and `AUTO-...` orders of that supplier). Approved and delivered orders are never changed.

## BOQ of a capacity request: Price check tab (1.10.0)

1. **Standard kits** (Management › Standard kits): for each resource you standardise, upload a CSV with the
   parts and quantities for ONE unit (`templates/BOQ_MODEL.csv`; Part Number and Qty are enough) and say what one
   kit covers: e.g. "Standard compute server" = 1 Physical server, "SAN storage 10 TB" = 10 TB, "GPU node" = 4 GPUs.
2. **Price lists (LOP)**: import the current LOP of every supplier.
3. Open the request ticket › **Price check** tab (shown on capacity request tickets to users who may create orders).
   It shows the request (type, resource, quantity, project), any BOQ already linked, and the matching kit × the
   requested quantity priced with **every supplier's current LOP**: one kit, the total, the % above the cheapest,
   how many parts each LOP has, and (click *parts*) every part with its unit price and where it comes from
   (LOP, kit file, or no price). The cheapest supplier whose LOP has all the parts is preselected.
4. Choose the supplier and click **Create draft BOQ with the selected supplier**: a **draft order** `LOP-<ticket>`
   is created on the project's budget (project › Costs tab) or the planning budget of the request type, linked to
   the ticket (ticket › Items), with a private note that records the supplier chosen, by whom, and every
   supplier's price. Nothing is written before you press the button.

Quantity: Compute → Quantity, Storage → Capacity in TB (GB converted), GPU → Number of GPUs; divided by what one kit
covers, rounded up. Requests without a resource category (e.g. modernization) or without a matching kit cannot be
priced here: create their order in Management › Orders and link it in ticket › Items. "Release old resources" is never priced.

**Settings** (Standard kits page): the planning budget per request type (New project / Expansion / Modernization),
used when the project has no budget yet. *Also create a draft BOQ automatically* (off by default; upgrading to
1.10.0 switches it off once): when on, the form creates the draft order `AUTO-<ticket>` with the kit's supplier
choice as soon as the ticket is created. Kit **Supplier choice**: *Cheapest LOP of all suppliers* or *Only the
supplier below*; it sets the preselected supplier in the Price check and the supplier of automatic BOQs.

## Demand & cost tab (Tools › Project reports)

Compares all capacity requests with each other by cost: every ticket created by the capacity request form, with
its request type, resource, quantity and the cost of its BOQ (created from the Price check with the LOP, or any order
linked in ticket › Items). Ranking by cost, cost by project and request type, cost by resource, and a ranked table with % of total and cost per unit. Filters: project, request type, resource, approval, BOQ stage.
Needs "See all" on Projects and Tickets and read on Budgets.

## Dashboards (removed in 1.18.1)

Versions 1.11 to 1.18.0 added four ready-made *Project reports* dashboards and *Project reports* widgets to GLPI's
dashboards. They were removed in 1.18.1: installing or updating the plugin deletes those dashboards and every
*Project reports* widget, and users whose default dashboard was one of them get GLPI's default again. GLPI's own
dashboards are not touched. All the figures are still in Tools › Project reports.

## LOM catalog (Tools › LOM catalog, 1.13.0, parts and devices since 1.14.0)

The list of materials: every device model in GLPI with its pictures, grouped as Servers, Storage, Network equipment,
Enclosures, Passive equipment, PDUs and Racks (storage models recorded as network equipment or as a custom asset type
are grouped under Storage by name, e.g. PowerStore, EMC, SAN, NetApp, DAE). Each card shows the picture, product
number, rack units, power, weight, depth and how many devices of that model are in use; click it for all pictures,
the full specs, the price in each supplier's LOP (matched by the model's **product number**, else its name) and links
to the devices and to the model form. Filter by group, search, "in use only", sort by name, use or price, and
**Download LOM (CSV)**.

- **Pictures** are GLPI's own: Setup › Dropdowns › *Computer models* (or Network equipment models, the custom asset
  type's models, …) › open a model › **Pictures** (several allowed), **Front picture**, **Rear picture**. A model
  without a picture shows a drawing of its device type.
- **Prices** are shown only to users who may see budgets or create orders; others see the catalog without prices.
- **Filters** (1.16.0): Servers, Storage, Switches (network equipment models), PDUs, Racks, then *Other*
  (passive equipment, enclosures).
- **LOMs from orders** (1.15.0): the second tab of the catalog lists every order of the Orders plugin as a LOM.
  Open one to see its materials: Part Number, Spare part number (the reference's manufacturer reference, or
  "Spare Part:" in its comment), description, type, quantity and, for users who may see costs, unit price and total.
  Users who may update orders set each LOM as **Server**, **Storage**, **Switch**, **PDU** or **Rack** and, optionally, the device model it is for;
  the LOM then also appears on that model's LOM sheet, so one model can have several LOMs from different orders.
  Filter by Server / Storage / Switch / PDU / Rack / Not set, search, CSV and print. The orders themselves are never changed.

- **LOM sheet** (1.14.0): click a model › *Open LOM sheet*. It shows the model's pictures and specs, then
  * **Parts list · one device**: Part Number, **Spare part number**, Description, Qty per device, the total for all
    devices in use, and the lowest LOP price per part with the parts cost per device. Users who may update the model
    add, edit and remove rows, or import a file (`templates/LOM_PARTS_TEMPLATE.csv`: Part Number, Spare Part number,
    Description, Qty; tick *Replace* to overwrite the list). Stored in the plugin's own table.
  * **Devices of this model**: every device with its **photo**, serial and inventory number, location and rack,
    status, in-service date, **age** (green under 3 years, amber 3–5, red 5+) and warranty end (red when expired),
    with average age, 5+ years and expired-warranty counts. The photo is any image attached in the device's
    **Documents** tab (several photos open in a viewer); the age comes from its **Management** tab (in-service date,
    else purchase or delivery date). "Add photo" / "add date" open those tabs.
  * **CSV** (parts and devices) and **Print**.

- **Read-only parts pages** (1.22.10): *Tools › LOM catalog* links to one page per device type (Servers, Storage,
  Switches, PDUs, Racks). Each lists every model with its parts list per supplier. Nothing on these pages can be changed.

- **LOM plans & budget** (1.22.13): *Tools › LOM catalog › LOM plans & budget*. A plan lists device models with their number of devices and a supplier
  (the cheapest complete LOP by default). Each plan is checked against a GLPI budget: value minus committed orders,
  pipeline orders, other costs and assets bought with that budget (the same rules as Budget & cost). What does not fit is shown
  as a shortfall, to be ordered in the next budget year. Prices are before tax. Needs the Budget read right; editing needs Budget update.

- Each group is shown only to users who may see that asset type. Read-only: the catalog never changes anything.

## Demo data (optional)

To see every chart filled before your real data is complete:

```bash
docker exec -it <glpi-container> php bin/console plugins:projectreports:demo            # create
docker exec -it <glpi-container> php bin/console plugins:projectreports:demo --remove   # delete it all again
```

It creates 4 projects ("DEMO ..."), their budgets, 44 servers with financial information, a data center "DEMO DC-1" with two halls and 14 racks (servers, switches, SAN and patch panels placed in them, with RAM, disks, NICs and batteries), 6 teams, 8 categories,
120 requests ("[DEMO] ...") and, when the Orders management plugin is active, a supplier, 6 catalog references and
12 BOQ orders in every state (draft, waiting for approval, validated, being delivered, delivered, paid, canceled).
With Project reports 1.6+, the capacity request form imported and the Orders plugin active, it also creates
4 DEMO standard kits and 12 DEMO capacity requests (new project / expansion / modernization; compute, storage, GPU;
approved, waiting and refused) with their automatic BOQ estimates, for the Demand & cost tab.
Everything is named DEMO and `--remove` deletes exactly that (your own kits and requests are not touched). No e-mails are sent.

## Extras

- `extras/forms/capacity-request-1-new-project.json` … `-4-release.json`: the capacity request, one form per request type (import all four)
- `extras/forms/project-based-capacity-planning-request-v2.json`: the earlier single form (all four types in one)
  (Administration › Forms › Import). Import creates a new form; deactivate the old one afterwards.
- `public/templates/BOQ_MODEL.csv`: the template for standard kit files (Part Number, Qty, …).

## License

GPLv3+ (see `LICENSE`).
