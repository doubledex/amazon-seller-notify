<?php

namespace Database\Seeders;

use App\Models\UsFcLocation;
use Illuminate\Database\Seeder;

class UsFcLocationsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = [
            ['fc' => 'ABQ1', 'city' => 'Albuquerque', 'state' => 'NM'],
            ['fc' => 'ACY1', 'city' => 'West Deptford', 'state' => 'NJ'],
            ['fc' => 'AGS1', 'city' => 'Appling', 'state' => 'GA'],
            ['fc' => 'AKC1', 'city' => 'Akron', 'state' => 'OH'],
            ['fc' => 'ATL2', 'city' => 'Stone Mountain', 'state' => 'GA'],
            ['fc' => 'AUS2', 'city' => 'Pflugerville', 'state' => 'TX'],
            ['fc' => 'AUS3', 'city' => 'Waco', 'state' => 'TX'],
            ['fc' => 'BDL2', 'city' => 'Windsor', 'state' => 'CT'],
            ['fc' => 'BDL3', 'city' => 'North Haven', 'state' => 'CT'],
            ['fc' => 'BDL4', 'city' => 'Windsor', 'state' => 'CT'],
            ['fc' => 'BFL1', 'city' => 'Bakersfield', 'state' => 'CA'],
            ['fc' => 'BFI4', 'city' => 'Kent', 'state' => 'WA'],
            ['fc' => 'BHM1', 'city' => 'Bessemer', 'state' => 'AL'],
            ['fc' => 'BOI2', 'city' => 'Nampa', 'state' => 'ID'],
            ['fc' => 'BOS3', 'city' => 'North Andover', 'state' => 'MA'],
            ['fc' => 'BWI2', 'city' => 'Baltimore', 'state' => 'MD'],
            ['fc' => 'BWI4', 'city' => 'Sparrows Point', 'state' => 'MD'],
            ['fc' => 'CAE1', 'city' => 'West Columbia', 'state' => 'SC'],
            ['fc' => 'CHA1', 'city' => 'Chattanooga', 'state' => 'TN'],
            ['fc' => 'CLE3', 'city' => 'Euclid', 'state' => 'OH'],
            ['fc' => 'CLT2', 'city' => 'Charlotte', 'state' => 'NC'],
            ['fc' => 'CLT4', 'city' => 'Charlotte', 'state' => 'NC'],
            ['fc' => 'CMH1', 'city' => 'Etna', 'state' => 'OH'],
            ['fc' => 'CMH3', 'city' => 'Monroe', 'state' => 'OH'],
            ['fc' => 'CMH4', 'city' => 'West Jefferson', 'state' => 'OH'],
            ['fc' => 'DAB2', 'city' => 'Bondurant', 'state' => 'IA'],
            ['fc' => 'DAL3', 'city' => 'Dallas', 'state' => 'TX'],
            ['fc' => 'DCA1', 'city' => 'Sparrows Point', 'state' => 'MD'],
            ['fc' => 'DEN3', 'city' => 'Thornton', 'state' => 'CO'],
            ['fc' => 'DEN4', 'city' => 'Colorado Springs', 'state' => 'CO'],
            ['fc' => 'DFW7', 'city' => 'Fort Worth', 'state' => 'TX'],
            ['fc' => 'DTW1', 'city' => 'Romulus', 'state' => 'MI'],
            ['fc' => 'ELP1', 'city' => 'El Paso', 'state' => 'TX'],
            ['fc' => 'EWR4', 'city' => 'Robbinsville', 'state' => 'NJ'],
            ['fc' => 'EWR9', 'city' => 'Carteret', 'state' => 'NJ'],
            ['fc' => 'FAT1', 'city' => 'Fresno', 'state' => 'CA'],
            ['fc' => 'FWA4', 'city' => 'Fort Wayne', 'state' => 'IN'],
            ['fc' => 'FTW6', 'city' => 'Oklahoma City', 'state' => 'OK'],
            ['fc' => 'GEG1', 'city' => 'Spokane', 'state' => 'WA'],
            ['fc' => 'GYR1', 'city' => 'Goodyear', 'state' => 'AZ'],
            ['fc' => 'GYR2', 'city' => 'Goodyear', 'state' => 'AZ'],
            ['fc' => 'HOU6', 'city' => 'Houston', 'state' => 'TX'],
            ['fc' => 'IAH3', 'city' => 'Houston', 'state' => 'TX'],
            ['fc' => 'IGQ1', 'city' => 'Channahon', 'state' => 'IL'],
            ['fc' => 'JAN1', 'city' => 'Canton', 'state' => 'MS'],
            ['fc' => 'JAX2', 'city' => 'Jacksonville', 'state' => 'FL'],
            ['fc' => 'JFK8', 'city' => 'Staten Island', 'state' => 'NY'],
            ['fc' => 'LAS2', 'city' => 'North Las Vegas', 'state' => 'NV'],
            ['fc' => 'LAS7', 'city' => 'North Las Vegas', 'state' => 'NV'],
            ['fc' => 'LBE1', 'city' => 'New Stanton', 'state' => 'PA'],
            ['fc' => 'LGA9', 'city' => 'Edison', 'state' => 'NJ'],
            ['fc' => 'LGB3', 'city' => 'Eastvale', 'state' => 'CA'],
            ['fc' => 'LGB7', 'city' => 'Rialto', 'state' => 'CA'],
            ['fc' => 'LIT1', 'city' => 'Little Rock', 'state' => 'AR'],
            ['fc' => 'MCO1', 'city' => 'Orlando', 'state' => 'FL'],
            ['fc' => 'MDW2', 'city' => 'Joliet', 'state' => 'IL'],
            ['fc' => 'MEM1', 'city' => 'Memphis', 'state' => 'TN'],
            ['fc' => 'MEM4', 'city' => 'Memphis', 'state' => 'TN'],
            ['fc' => 'MIA1', 'city' => 'Opa-Locka', 'state' => 'FL'],
            ['fc' => 'MKE1', 'city' => 'Kenosha', 'state' => 'WI'],
            ['fc' => 'MKE2', 'city' => 'Oak Creek', 'state' => 'WI'],
            ['fc' => 'MQY1', 'city' => 'Mt. Juliet', 'state' => 'TN'],
            ['fc' => 'MSP1', 'city' => 'Shakopee', 'state' => 'MN'],
            ['fc' => 'OAK4', 'city' => 'Tracy', 'state' => 'CA'],
            ['fc' => 'OMA2', 'city' => 'Papillion', 'state' => 'NE'],
            ['fc' => 'ONT8', 'city' => 'Moreno Valley', 'state' => 'CA'],
            ['fc' => 'ORD5', 'city' => 'Waukegan', 'state' => 'IL'],
            ['fc' => 'ORF4', 'city' => 'Suffolk', 'state' => 'VA'],
            ['fc' => 'OXR1', 'city' => 'Oxnard', 'state' => 'CA'],
            ['fc' => 'PAE2', 'city' => 'Arlington', 'state' => 'WA'],
            ['fc' => 'PDX8', 'city' => 'Portland', 'state' => 'OR'],
            ['fc' => 'PDX9', 'city' => 'Troutdale', 'state' => 'OR'],
            ['fc' => 'PSC2', 'city' => 'Pasco', 'state' => 'WA'],
            ['fc' => 'PSP1', 'city' => 'Beaumont', 'state' => 'CA'],
            ['fc' => 'PVD2', 'city' => 'Johnston', 'state' => 'RI'],
            ['fc' => 'RDU1', 'city' => 'Garner', 'state' => 'NC'],
            ['fc' => 'RDU4', 'city' => 'Fayetteville', 'state' => 'NC'],
            ['fc' => 'ROC1', 'city' => 'Rochester', 'state' => 'NY'],
            ['fc' => 'RMN3', 'city' => 'Fredericksburg', 'state' => 'VA'],
            ['fc' => 'SAN3', 'city' => 'Otay Mesa', 'state' => 'CA'],
            ['fc' => 'SAT3', 'city' => 'San Antonio', 'state' => 'TX'],
            ['fc' => 'SAV4', 'city' => 'Pooler', 'state' => 'GA'],
            ['fc' => 'SAV7', 'city' => 'Savannah', 'state' => 'GA'],
            ['fc' => 'SBD1', 'city' => 'Bloomington', 'state' => 'CA'],
            ['fc' => 'SBD6', 'city' => 'Bloomington', 'state' => 'CA'],
            ['fc' => 'SCK6', 'city' => 'Stockton', 'state' => 'CA'],
            ['fc' => 'SHV1', 'city' => 'Shreveport', 'state' => 'LA'],
            ['fc' => 'SLC1', 'city' => 'Salt Lake City', 'state' => 'UT'],
            ['fc' => 'SMF1', 'city' => 'Sacramento', 'state' => 'CA'],
            ['fc' => 'SYR1', 'city' => 'Clay', 'state' => 'NY'],
            ['fc' => 'TLH2', 'city' => 'Tallahassee', 'state' => 'FL'],
            ['fc' => 'TMB8', 'city' => 'Homestead', 'state' => 'FL'],
            ['fc' => 'TPA1', 'city' => 'Ruskin', 'state' => 'FL'],
            ['fc' => 'TPA4', 'city' => 'Temple Terrace', 'state' => 'FL'],
            ['fc' => 'TUL2', 'city' => 'Tulsa', 'state' => 'OK'],
            ['fc' => 'TUS2', 'city' => 'Tucson', 'state' => 'AZ'],
            ['fc' => 'VGT1', 'city' => 'North Las Vegas', 'state' => 'NV'],
            ['fc' => 'VGT2', 'city' => 'North Las Vegas', 'state' => 'NV'],
        ];

        $payload = array_map(static function (array $row) use ($now): array {
            $fc = strtoupper(trim($row['fc']));
            $city = trim($row['city']);
            $state = strtoupper(trim($row['state']));

            return [
                'fulfillment_center_id' => $fc,
                'city' => $city,
                'state' => $state,
                'country_code' => 'US',
                'label' => "{$fc} - {$city}, {$state}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        UsFcLocation::upsert(
            $payload,
            ['fulfillment_center_id'],
            ['city', 'state', 'country_code', 'label', 'updated_at']
        );
    }
}
