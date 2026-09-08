<?php
// Build: 2026-09-08-B
// ============================================================
// SINGLE SOURCE OF TRUTH for every retreat - upcoming, sold out, or
// long past. index.php loops over this to build the Save-the-Date
// grid; event.php (the shared flyer template) reads one entry by
// ?slug= to render that event's page; merch.php's pickup dropdown
// reads it for the "I'll pick it up at a retreat" list; gallery.php
// reads it for event names/dates, then looks for real photos in
// that same folder (see below).
//
// Adding a new retreat = add one entry here + upload its flyer/thumb
// to events/<slug>/. No new PHP/HTML file needed.
//
// 2026-09-08 (gallery/events unification): this file used to be
// upcoming-only - photo galleries lived in a completely separate
// /gallery/<slug>/event.txt per folder, which meant a retreat's name
// and dates were typed by hand in TWO places that could drift apart.
// Now there's one. What changed:
//   - startDate/endDate (ISO, YYYY-MM-DD) added - the only fields
//     any code reads to sort events or decide gallery Past/Upcoming.
//     dateRange is UNCHANGED - still hand-typed display text, still
//     the only thing shown to visitors. Two fields, same reasoning
//     as gallery event.txt's dates:/date: had: one for people, one
//     for code, because code can't reliably reparse hand-typed text
//     back into a real date.
//   - archived (bool) added - a MANUAL switch, not date-driven. true
//     hides an entry from index.php's grid and merch.php's pickup
//     dropdown (and skips it in event.php - see the note there) but
//     NEVER from gallery.php, which always shows every event, split
//     into Past/Upcoming purely by date. Steve, this only takes
//     effect when you set it - nothing here auto-archives an event
//     whose date has passed, on purpose (explicit beats guessed).
//   - flyerImage/thumbImage moved from flat /events/*.png into each
//     event's own folder (events/<slug>/flyer.png, .../flyer_thumb.jpg)
//     so a retreat's flyer and its photo gallery live in one place.
//   - Photos for a retreat now live in events/<slug>/photos/ (numbered
//     01-, 02-, ... for display order; captions.txt same as before).
//     gallery.php shows, in order: the first real photo in that
//     folder if there is one, else the flyer as a stand-in cover,
//     else a plain "Photos coming soon" placeholder. The flyer is
//     NEVER a swipeable slide itself (it's a promo graphic, not a
//     retreat photo) - same idea as a video's poster frame being
//     attached, not listed.
//   - Retreats that predate this whole registration system (like
//     Chasin' Fireflies below) got backfilled here too, as
//     archived:true, with every registration-only field left null -
//     they never had hotel/pricing/booking info to begin with, and
//     event.php treats any archived entry as "not found" rather than
//     rendering a registration page with holes in it (see event.php).
//
// Every field below is THIS event's own data - nothing is assumed
// shared with any other event, even if two retreats happen to share
// a hotel or price. Fill in each event's real numbers independently.
// ============================================================

return [
    'chasin-fireflies-apr-2026' => [
        'title' => "Chasin' Fireflies 2026",
        'dateRange' => 'Apr 10–12, 2026',
        'startDate' => '2026-04-10',
        'endDate' => '2026-04-12',
        'archived' => true,
        'registerLabel' => null,
        'flyerImage' => null,
        'thumbImage' => null,
        'soldOut' => null,
        'costText' => null,
        'scheduleText' => null,
        'hotelName' => null,
        'hotelAddress' => null,
        'hotelLink' => null,
        'hotelRateNote' => null,
        'bookingLink' => null,
    ],
    'drive-in-aug-2026' => [
        'title' => 'Long Southern Summer Nights Drive-In',
        'dateRange' => 'Aug 27–30, 2026',
        'startDate' => '2026-08-27',
        'endDate' => '2026-08-30',
        'archived' => true,
        'registerLabel' => null,
        'flyerImage' => 'events/drive-in-aug-2026/flyer.png',
        'thumbImage' => 'events/drive-in-aug-2026/flyer_thumb.jpg',
        'soldOut' => true,
        'costText' => '$185 for 3 days, $215 for 4 days.',
        'scheduleText' => 'Events begin at 12pm Thursday and run until 4pm on Sunday. If you purchase 3 days, you can arrive anytime on Friday.',
        'hotelName' => 'Holiday Inn Rock Hill',
        'hotelAddress' => '503 Galleria Boulevard, Rock Hill, SC 29730, United States',
        'hotelLink' => 'https://www.ihg.com/holidayinn/hotels/us/en/rock-hill/clthi/hoteldetail',
        'hotelRateNote' => 'The hotel will provide a booking link about 3 months before the event date. Hotel block rate is usually $124–$129 a night, plus taxes.',
        'bookingLink' => null,
    ],
    'sunflower-sept-2026' => [
        'title' => 'Sunflower Fields & Southern Dreams',
        'dateRange' => 'Sep 10–13, 2026',
        'startDate' => '2026-09-10',
        'endDate' => '2026-09-13',
        'archived' => false,
        'registerLabel' => 'September 10-13, 2026 - Sunflower Fields & Southern Dreams',
        'flyerImage' => 'events/sunflower-sept-2026/flyer.png',
        'thumbImage' => 'events/sunflower-sept-2026/flyer_thumb.jpg',
        'soldOut' => false,
        'costText' => '$185 for 3 days, $215 for 4 days.',
        'scheduleText' => 'Events begin at 12pm Thursday and run until 4pm on Sunday. If you purchase 3 days, you can arrive anytime on Friday.',
        'hotelName' => 'Holiday Inn Rock Hill',
        'hotelAddress' => '503 Galleria Boulevard, Rock Hill, SC 29730, United States',
        'hotelLink' => 'https://www.ihg.com/holidayinn/hotels/us/en/rock-hill/clthi/hoteldetail',
        'hotelRateNote' => 'Hotel block rate is usually $124–$129 a night, plus taxes.',
        'bookingLink' => 'https://www.ihg.com/redirect?path=rates&brandCode=HI&localeCode=en&regionCode=1&hotelCode=CLTHI&checkInDate=10&checkInMonthYear=082026&checkOutDate=13&checkOutMonthYear=082026&_PMID=99801505&GPC=SFS&cn=no&adjustMonth=false&showApp=true&monthIndex=00',
    ],
    'country-roads-feb-2027' => [
        'title' => 'Country Roads Take Me Home',
        'dateRange' => 'Feb 18–21, 2027',
        'startDate' => '2027-02-18',
        'endDate' => '2027-02-21',
        'archived' => false,
        'registerLabel' => 'February 18-21, 2027 - Country Roads Take Me Home',
        'flyerImage' => 'events/country-roads-feb-2027/flyer.png',
        'thumbImage' => 'events/country-roads-feb-2027/flyer_thumb.jpg',
        'soldOut' => false,
        'costText' => '$185 for 3 days, $215 for 4 days.',
        'scheduleText' => 'Events begin at 12pm Thursday and run until 4pm on Sunday. If you purchase 3 days, you can arrive anytime on Friday.',
        'hotelName' => 'Holiday Inn Rock Hill',
        'hotelAddress' => '503 Galleria Boulevard, Rock Hill, SC 29730, United States',
        'hotelLink' => 'https://www.ihg.com/holidayinn/hotels/us/en/rock-hill/clthi/hoteldetail',
        'hotelRateNote' => 'The hotel will provide a booking link about 3 months before the event date. Hotel block rate is usually $124–$129 a night, plus taxes.',
        'bookingLink' => null,
    ],
    'front-porch-apr-2027' => [
        'title' => 'Front Porch and Fireflies',
        'dateRange' => 'Apr 8–11, 2027',
        'startDate' => '2027-04-08',
        'endDate' => '2027-04-11',
        'archived' => false,
        'registerLabel' => 'April 8-11, 2027 - Front Porch and Fireflies',
        'flyerImage' => 'events/front-porch-apr-2027/flyer.png',
        'thumbImage' => 'events/front-porch-apr-2027/flyer_thumb.jpg',
        'soldOut' => false,
        'costText' => '$185 for 3 days, $215 for 4 days.',
        'scheduleText' => 'Events begin at 12pm Thursday and run until 4pm on Sunday. If you purchase 3 days, you can arrive anytime on Friday.',
        'hotelName' => 'Holiday Inn Rock Hill',
        'hotelAddress' => '503 Galleria Boulevard, Rock Hill, SC 29730, United States',
        'hotelLink' => 'https://www.ihg.com/holidayinn/hotels/us/en/rock-hill/clthi/hoteldetail',
        'hotelRateNote' => 'The hotel will provide a booking link about 3 months before the event date. Hotel block rate is usually $124–$129 a night, plus taxes.',
        'bookingLink' => null,
    ],
    'dog-days-aug-2027' => [
        'title' => 'Dog Days of Summer',
        'dateRange' => 'Aug 12–15, 2027',
        'startDate' => '2027-08-12',
        'endDate' => '2027-08-15',
        'archived' => false,
        'registerLabel' => 'August 12-15, 2027 - Dog Days of Summer',
        'flyerImage' => 'events/dog-days-aug-2027/flyer.png',
        'thumbImage' => 'events/dog-days-aug-2027/flyer_thumb.jpg',
        'soldOut' => false,
        'costText' => '$185 for 3 days, $215 for 4 days.',
        'scheduleText' => 'Events begin at 12pm Thursday and run until 4pm on Sunday. If you purchase 3 days, you can arrive anytime on Friday.',
        'hotelName' => 'Holiday Inn Rock Hill',
        'hotelAddress' => '503 Galleria Boulevard, Rock Hill, SC 29730, United States',
        'hotelLink' => 'https://www.ihg.com/holidayinn/hotels/us/en/rock-hill/clthi/hoteldetail',
        'hotelRateNote' => 'The hotel will provide a booking link about 3 months before the event date. Hotel block rate is usually $124–$129 a night, plus taxes.',
        'bookingLink' => null,
    ],
];
