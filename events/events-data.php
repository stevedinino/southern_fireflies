<?php
// Build: 2026-08-01-A
// ============================================================
// SINGLE SOURCE OF TRUTH for retreat events. index.php loops over
// this to build the Save-the-Date grid; event.php (the shared flyer
// template) reads one entry by ?slug= to render that event's page.
//
// Adding a new retreat = add one entry here + upload its flyer/thumb
// images to /events. No new PHP/HTML file needed.
//
// Every field below is THIS event's own data - nothing is assumed
// shared with any other event, even if two retreats happen to share
// a hotel or price. Fill in each event's real numbers independently.
// ============================================================

return [
    'drive-in-aug-2026' => [
        'title' => 'Long Southern Summer Nights Drive-In',
        'dateRange' => 'Aug 27–30, 2026',
        'registerLabel' => null,
        'flyerImage' => 'events/SFF_August_2026.png',
        'thumbImage' => 'events/SFF_August_2026_thumb.jpg',
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
        'registerLabel' => 'September 10-13, 2026 - Sunflower Fields & Southern Dreams',
        'flyerImage' => 'events/september.png',
        'thumbImage' => 'events/september_thumb.jpg',
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
        'registerLabel' => 'February 18-21, 2027 - Country Roads Take Me Home',
        'flyerImage' => 'events/feb2027.png',
        'thumbImage' => 'events/feb2027_thumb.jpg',
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
        'registerLabel' => 'April 8-11, 2027 - Front Porch and Fireflies',
        'flyerImage' => 'events/apr2027.png',
        'thumbImage' => 'events/apr2027_thumb.jpg',
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
        'registerLabel' => 'August 12-15, 2027 - Dog Days of Summer',
        'flyerImage' => 'events/aug2027.png',
        'thumbImage' => 'events/aug2027_thumb.jpg',
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
