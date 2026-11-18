# ra_tweaks

A Joomla system plugin for East Cheshire Ramblers site tweaks.

The first tweak highlights rendered Ramblers walk titles that contain a configurable marker such as `***`, while skipping cancelled walks.

## Behaviour

- Looks at the final rendered site HTML on any site page or article.
- Always loads a browser-side pass for programme pages that populate walks after page load.
- Avoids fragile browser regex escaping so Joomla optimization plugins can safely process the script.
- Finds rendered walk items containing the marker, defaulting to `***`.
- Moves the marker to the start of the walk text, before the date.
- Renders the marker as a small circular badge using the configured colour and white marker text.
- Skips items whose nearby rendered text contains `cancelled` or `canceled`.
- Also skips struck-through items.
- Applies the configured colour to the leading changed-walk text, including the date and title.
- Colours the programme-page changed-walk row text after the grade icon, including date, title, distance, and contact.
- Adds an **RA Tweaks** administrator menu item under **Components** that opens this plugin's settings.
- Optionally aligns programme walk grade icons beside wrapped title text.
- Optionally appends a coloured walk type label (STROLLER, SHORT WALK, MEDIUM WALK or LONG WALK) to the end of each programme walk title, based on the walk's mileage, so it no longer needs to be typed in manually. Skips walks that already contain a manual walk type in the title, and skips cancelled walks.
- Evening walks: since start time isn't shown on the programme listing, walk leaders add `- EVENING WALK` anywhere in the title as before. The plugin then moves that text to the end of the title and applies its own configurable colour, consistent with the mileage-based labels. Mileage bands are not applied to evening walks.
- Diary Dates module: adds a coloured category icon to the left of each entry (Committee Meeting, Social Event, Training, Weekend Away, Coach Trip, Themed Walk), looked up from the linked event's `event_type_id` in the `#__ra_events` table. Runs server-side since the category isn't present in the rendered listing markup, only in the database. Icons are drawn as inline SVG (not emoji) for consistent rendering across browsers, and wrapped titles hang-indent under the text rather than the icon.
- Walk routes (optional, off by default): walk leaders attach a GPX file to any upcoming walk, and the walk's popup then shows **View route** and **Download GPX** buttons. See [Walk routes](#walk-routes).

## Walk routes

Walks come from Ramblers Walks Manager, so the plugin stores each route itself and links it to the walk by its Walks Manager id. One route per walk; a new upload replaces the old one.

Set up (Plugins > System - RA Tweaks > Walk Routes):

1. Create a Joomla user group for walk leaders (for example "Walk leaders") and a matching access level, and add the leaders to the group.
2. Create an article containing `{ra_walk_route_upload}` and give it the leaders' access level. Put it in a menu if you want. Leaders choose any upcoming walk, upload a `.gpx` file, and can remove routes.
3. Create an article containing `{ra_walk_route}` with the Public access level. It shows the map and a download link for `?walk=<id>`. Needs the Ramblers library, which draws the map.
4. In the plugin settings turn on **Enable walk routes**, pick the group, enter the Walks Manager group code (blank uses the home group from Ramblers Tools), and enter the address of the article from step 3.

Behaviour:

- Anyone can view and download a route. Anyone in the chosen group, and Super Users, can add or remove them.
- Uploads are checked as real GPX (at least two track or route points, no DOCTYPE or entities), limited in size, and saved under a random file name in `images/walk-routes/`.
- The list of walks is the group's upcoming walks from the Walks Manager feed (cancelled walks left out), cached for ten minutes. Leaders can attach a route to any of them, because the leader's name is not always given.
- The popup button script wraps `ra.walk.displayWalkID` and adds the buttons to the popup header when the walk has a route. The list of walks with routes comes from `index.php?option=com_ajax&plugin=ra_tweaks&group=system&format=raw&task=routes`.
- A route is deleted automatically 30 days (setting) after its walk. The check runs at most once a day.
- The `#__ra_tweaks_walk_routes` table is created the first time it is needed. Uninstalling the plugin leaves the table and files in place.

## Install

Zip the contents of `plg_system_ra_tweaks` and install the zip in Joomla:

```sh
cd plg_system_ra_tweaks
zip -r ../ra_tweaks-1.4.9.zip .
```

Then in Joomla:

1. Go to **System > Install > Extensions**.
2. Upload `ra_tweaks-1.4.9.zip`.
3. Go to **System > Manage > Plugins**.
4. Enable **System - RA Tweaks**.
5. Configure the marker and colour.

## Updates

The plugin manifest registers a Joomla update server:

`https://raw.githubusercontent.com/Ramblers-Tools/ra_tweaks/main/updates/ra_tweaks.xml`

For each release, update `updates/ra_tweaks.xml` to the new version and make sure the `downloadurl` points to the matching GitHub release asset.

## Useful Settings

- **Changed marker**: `***`
- **Highlight colour**: any hex colour, defaulting to the East Cheshire logo orange `#F08050`
- **Walk type labelling mileage bands** (up to and including each maximum):
  - Stroller: up to 4 miles
  - Short Walk: 4.1–7.9 miles
  - Medium Walk: 8–10.9 miles
  - Long Walk: 11+ miles
  - Evening Walk is not detected automatically (walk start times aren't shown on the programme page) — keep adding it manually to the title.

The marker remains visible because the website key uses it to denote changed walk details.
