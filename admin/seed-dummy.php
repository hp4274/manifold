<?php
/**
 * Ten made-up applications, paid in full, for trying things out — the raffle in
 * particular, which needs a hat with something in it.
 *
 * Fills every queue the sidebar lists — stove applications, TukTuk applications,
 * contact enquiries and newsletter signups — spread across the country and across
 * the states the office moves them through, so none of them is uniform. The
 * newsletter gets a hundred and twenty, enough to be worth paging through.
 *
 * Every application gets a verified payment for the full fee and then goes
 * through sync_application_status(), so it arrives at `complete` the same way a
 * real one does rather than being written straight to that status.
 *
 * The people come in two batches. The first ten spell out every field; the twenty
 * after them give the part that has to be written by hand — who they are and
 * where they live — and dummy_expand() fills the rest from the values the form
 * itself offers, rotating through them so no two records read alike.
 *
 * Every address is @example.com and every IP is in 203.0.113.0/24, the range set
 * aside for documentation. That is what makes these findable and safe: nothing
 * here can ever be emailed, and removing them is one query per table.
 *
 *   php admin/seed-dummy.php            add them (skips any already there)
 *   php admin/seed-dummy.php --remove   delete them again
 *
 * Command line only. Loading it in a browser does nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Command line only.\n");
}

require_once __DIR__ . '/lib.php';

const DUMMY_DOMAIN = '@example.com';

/** The made-up people. Everything else is worked out from these. */
function dummy_people(): array
{
    return [
        [
            'product' => 'stove',
            'full_name' => 'Ananya Iyer', 'date_of_birth' => '1988-04-17', 'gender' => 'Female',
            'occupation' => 'School teacher',
            'mobile_number' => '9840123476', 'alt_mobile_number' => '9840123477',
            'email' => 'ananya.iyer' . DUMMY_DOMAIN, 'id_number' => 'AAIPI4821K',
            'house_number' => '3B, Kaveri Apartments', 'street' => 'Luz Church Road',
            'city' => 'Chennai', 'state' => 'Tamil Nadu', 'pin_code' => '600004',
            'property_type' => 'Apartment', 'ownership_status' => 'Owner', 'household_members' => 4,
            'existing_fuel' => 'LPG', 'units_required' => 1, 'intended_usage' => 'Personal',
            'expected_daily_usage' => '3–4 hours', 'water_source' => 'Municipal Water',
            'monthly_gas_consumption' => '1 cylinder (14.2 kg)', 'monthly_electric_consumption' => '210 units',
            'carbon_interest' => 'High', 'payment_method' => 'Full Payment',
            'financing_option' => '', 'bank_name' => 'Indian Bank',
            'referral_source' => 'Social Media', 'days_ago' => 46,
        ],
        [
            'product' => 'tuktuk',
            'full_name' => 'Rohit Deshmukh', 'date_of_birth' => '1985-11-02', 'gender' => 'Male',
            'occupation' => 'Auto rickshaw driver',
            'mobile_number' => '9822014589', 'alt_mobile_number' => '',
            'email' => 'rohit.deshmukh' . DUMMY_DOMAIN, 'id_number' => 'BQRPD9013M',
            'house_number' => '14, Shivneri Chawl', 'street' => 'Kothrud Depot Road',
            'city' => 'Pune', 'state' => 'Maharashtra', 'pin_code' => '411038',
            'property_type' => 'Individual Owner-Driver', 'ownership_status' => 'Owner', 'household_members' => 5,
            'existing_fuel' => 'CNG', 'units_required' => 1, 'intended_usage' => 'Commercial',
            'expected_daily_usage' => '10–12 hours', 'water_source' => 'Municipal Water',
            'monthly_gas_consumption' => '₹6,200 of CNG', 'monthly_electric_consumption' => '160 units',
            'carbon_interest' => 'Medium', 'payment_method' => 'Installments',
            'financing_option' => 'Six monthly instalments', 'bank_name' => 'Bank of Maharashtra',
            'referral_source' => 'Friend / Family', 'days_ago' => 41,
        ],
        [
            'product' => 'stove',
            'full_name' => 'Meera Nair', 'date_of_birth' => '1992-07-29', 'gender' => 'Female',
            'occupation' => 'Nurse',
            'mobile_number' => '9847556210', 'alt_mobile_number' => '4842201188',
            'email' => 'meera.nair' . DUMMY_DOMAIN, 'id_number' => 'CJKPN2277L',
            'house_number' => 'Nandanam, 22/415', 'street' => 'Panampilly Nagar',
            'city' => 'Kochi', 'state' => 'Kerala', 'pin_code' => '682036',
            'property_type' => 'Villa', 'ownership_status' => 'Owner', 'household_members' => 3,
            'existing_fuel' => 'Piped Natural Gas', 'units_required' => 1, 'intended_usage' => 'Personal',
            'expected_daily_usage' => '2–3 hours', 'water_source' => 'Well Water',
            'monthly_gas_consumption' => '18 SCM piped gas', 'monthly_electric_consumption' => '185 units',
            'carbon_interest' => 'High', 'payment_method' => 'Full Payment',
            'financing_option' => '', 'bank_name' => 'Federal Bank',
            'referral_source' => 'Website', 'days_ago' => 37,
        ],
        [
            'product' => 'tuktuk',
            'full_name' => 'Imran Qureshi', 'date_of_birth' => '1979-01-14', 'gender' => 'Male',
            'occupation' => 'Fleet supervisor',
            'mobile_number' => '9701338842', 'alt_mobile_number' => '9701338843',
            'email' => 'imran.qureshi' . DUMMY_DOMAIN, 'id_number' => 'DLMPQ6654H',
            'house_number' => '8-2-120/A', 'street' => 'Road No. 2, Banjara Hills',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pin_code' => '500034',
            'property_type' => 'Fleet Operator', 'ownership_status' => 'Owner', 'household_members' => 6,
            'existing_fuel' => 'Petrol', 'units_required' => 4, 'intended_usage' => 'Fleet',
            'expected_daily_usage' => '14 hours across two shifts', 'water_source' => 'Borewell',
            'monthly_gas_consumption' => '₹24,000 of petrol', 'monthly_electric_consumption' => '340 units',
            'carbon_interest' => 'High', 'payment_method' => 'Lease-to-Own',
            'financing_option' => 'Twelve month lease', 'bank_name' => 'HDFC Bank',
            'referral_source' => 'Distributor', 'days_ago' => 33,
        ],
        [
            'product' => 'stove',
            'full_name' => 'Kavita Sharma', 'date_of_birth' => '1990-09-05', 'gender' => 'Female',
            'occupation' => 'Runs a tiffin service',
            'mobile_number' => '9414207731', 'alt_mobile_number' => '',
            'email' => 'kavita.sharma' . DUMMY_DOMAIN, 'id_number' => 'EPQPS1190C',
            'house_number' => 'B-47, Shanti Path', 'street' => 'Malviya Nagar',
            'city' => 'Jaipur', 'state' => 'Rajasthan', 'pin_code' => '302017',
            'property_type' => 'Commercial Kitchen', 'ownership_status' => 'Tenant', 'household_members' => 4,
            'existing_fuel' => 'LPG', 'units_required' => 2, 'intended_usage' => 'Commercial',
            'expected_daily_usage' => '8 hours', 'water_source' => 'Municipal Water',
            'monthly_gas_consumption' => '4 commercial cylinders', 'monthly_electric_consumption' => '420 units',
            'carbon_interest' => 'Medium', 'payment_method' => 'Installments',
            'financing_option' => 'Three monthly instalments', 'bank_name' => 'State Bank of India',
            'referral_source' => 'Exhibition', 'days_ago' => 28,
        ],
        [
            'product' => 'tuktuk',
            'full_name' => 'Sandeep Yadav', 'date_of_birth' => '1983-03-23', 'gender' => 'Male',
            'occupation' => 'Auto rickshaw owner-driver',
            'mobile_number' => '9935471002', 'alt_mobile_number' => '5222665510',
            'email' => 'sandeep.yadav' . DUMMY_DOMAIN, 'id_number' => 'FRSPY7742J',
            'house_number' => '221/C, Sector 9', 'street' => 'Vikas Nagar',
            'city' => 'Lucknow', 'state' => 'Uttar Pradesh', 'pin_code' => '226022',
            'property_type' => 'Individual Owner-Driver', 'ownership_status' => 'Owner', 'household_members' => 5,
            'existing_fuel' => 'Auto Engine', 'units_required' => 1, 'intended_usage' => 'Commercial',
            'expected_daily_usage' => '9–10 hours', 'water_source' => 'Borewell',
            'monthly_gas_consumption' => '₹5,400 of CNG', 'monthly_electric_consumption' => '140 units',
            'carbon_interest' => 'Low', 'payment_method' => 'Full Payment',
            'financing_option' => '', 'bank_name' => 'Punjab National Bank',
            'referral_source' => 'Advertisement', 'days_ago' => 24,
        ],
        [
            'product' => 'stove',
            'full_name' => 'Priya Banerjee', 'date_of_birth' => '1994-12-11', 'gender' => 'Female',
            'occupation' => 'Software engineer',
            'mobile_number' => '9830112204', 'alt_mobile_number' => '',
            'email' => 'priya.banerjee' . DUMMY_DOMAIN, 'id_number' => 'GTUPB3308N',
            'house_number' => 'Flat 6D, Aurora Heights', 'street' => 'Southern Avenue',
            'city' => 'Kolkata', 'state' => 'West Bengal', 'pin_code' => '700029',
            'property_type' => 'Apartment', 'ownership_status' => 'Tenant', 'household_members' => 2,
            'existing_fuel' => 'Induction', 'units_required' => 1, 'intended_usage' => 'Personal',
            'expected_daily_usage' => '1–2 hours', 'water_source' => 'Municipal Water',
            'monthly_gas_consumption' => 'None — all electric', 'monthly_electric_consumption' => '260 units',
            'carbon_interest' => 'High', 'payment_method' => 'Full Payment',
            'financing_option' => '', 'bank_name' => 'ICICI Bank',
            'referral_source' => 'Social Media', 'days_ago' => 19,
        ],
        [
            'product' => 'tuktuk',
            'full_name' => 'Vikram Chauhan', 'date_of_birth' => '1987-06-08', 'gender' => 'Male',
            'occupation' => 'Transport contractor',
            'mobile_number' => '9826745513', 'alt_mobile_number' => '9826745514',
            'email' => 'vikram.chauhan' . DUMMY_DOMAIN, 'id_number' => 'HVWPC5521R',
            'house_number' => '19, Scheme 78', 'street' => 'Vijay Nagar',
            'city' => 'Indore', 'state' => 'Madhya Pradesh', 'pin_code' => '452010',
            'property_type' => 'Fleet Operator', 'ownership_status' => 'Owner', 'household_members' => 7,
            'existing_fuel' => 'Diesel', 'units_required' => 3, 'intended_usage' => 'Fleet',
            'expected_daily_usage' => '12 hours', 'water_source' => 'Borewell',
            'monthly_gas_consumption' => '₹31,000 of diesel', 'monthly_electric_consumption' => '390 units',
            'carbon_interest' => 'Medium', 'payment_method' => 'Installments',
            'financing_option' => 'Nine monthly instalments', 'bank_name' => 'Axis Bank',
            'referral_source' => 'Distributor', 'days_ago' => 14,
        ],
        [
            'product' => 'stove',
            'full_name' => 'Divya Reddy', 'date_of_birth' => '1991-02-26', 'gender' => 'Female',
            'occupation' => 'Hostel warden',
            'mobile_number' => '9880334417', 'alt_mobile_number' => '8025569940',
            'email' => 'divya.reddy' . DUMMY_DOMAIN, 'id_number' => 'IWXPR8836D',
            'house_number' => 'Block C, Sunrise Residency', 'street' => '5th Cross, Indiranagar',
            'city' => 'Bengaluru', 'state' => 'Karnataka', 'pin_code' => '560038',
            'property_type' => 'Institutional Kitchen', 'ownership_status' => 'Tenant', 'household_members' => 12,
            'existing_fuel' => 'Piped Natural Gas', 'units_required' => 3, 'intended_usage' => 'Institutional',
            'expected_daily_usage' => '6–7 hours', 'water_source' => 'Municipal Water',
            'monthly_gas_consumption' => '46 SCM piped gas', 'monthly_electric_consumption' => '780 units',
            'carbon_interest' => 'High', 'payment_method' => 'Full Payment',
            'financing_option' => '', 'bank_name' => 'Canara Bank',
            'referral_source' => 'Website', 'days_ago' => 9,
        ],
        [
            'product' => 'tuktuk',
            'full_name' => 'Manjeet Singh', 'date_of_birth' => '1981-08-19', 'gender' => 'Male',
            'occupation' => 'Auto rickshaw driver',
            'mobile_number' => '9878002143', 'alt_mobile_number' => '',
            'email' => 'manjeet.singh' . DUMMY_DOMAIN, 'id_number' => 'JXYPS4409T',
            'house_number' => 'H. No. 302, Street 4', 'street' => 'Model Town Extension',
            'city' => 'Ludhiana', 'state' => 'Punjab', 'pin_code' => '141002',
            'property_type' => 'Individual Owner-Driver', 'ownership_status' => 'Owner', 'household_members' => 4,
            'existing_fuel' => 'Petrol', 'units_required' => 1, 'intended_usage' => 'Personal',
            'expected_daily_usage' => '7–8 hours', 'water_source' => 'Municipal Water',
            'monthly_gas_consumption' => '₹7,100 of petrol', 'monthly_electric_consumption' => '175 units',
            'carbon_interest' => 'Medium', 'payment_method' => 'Full Payment',
            'financing_option' => '', 'bank_name' => 'Punjab & Sind Bank',
            'referral_source' => 'Friend / Family', 'days_ago' => 5,
        ],
    ];
}

/**
 * Twenty more people, ten per product: the part that has to be written by hand.
 * dummy_expand() fills in the rest.
 */
function dummy_batch_two(): array
{
    return [
        /* ---- stove ---- */
        ['product' => 'stove', 'full_name' => 'Aditi Kulkarni', 'date_of_birth' => '1986-05-12',
         'gender' => 'Female', 'occupation' => 'College lecturer', 'mobile_number' => '9822337104',
         'id_number' => 'KAAPK1204B', 'house_number' => 'Flat 12, Sahyog Apartments',
         'street' => 'Ramdaspeth', 'city' => 'Nagpur', 'state' => 'Maharashtra', 'pin_code' => '440010'],

        ['product' => 'stove', 'full_name' => 'Rakesh Choudhary', 'date_of_birth' => '1981-10-30',
         'gender' => 'Male', 'occupation' => 'Bank clerk', 'mobile_number' => '9425118876',
         'id_number' => 'LBBPC3391F', 'house_number' => 'E-9, Char Imli',
         'street' => 'Shyamla Hills Road', 'city' => 'Bhopal', 'state' => 'Madhya Pradesh', 'pin_code' => '462003'],

        ['product' => 'stove', 'full_name' => 'Sarita Bhosale', 'date_of_birth' => '1993-02-08',
         'gender' => 'Female', 'occupation' => 'Runs a canteen', 'mobile_number' => '9975420613',
         'id_number' => 'MCCPB7745G', 'house_number' => '204, Laxmi Nivas',
         'street' => 'Hotgi Road', 'city' => 'Solapur', 'state' => 'Maharashtra', 'pin_code' => '413001'],

        ['product' => 'stove', 'full_name' => 'Naveen Kamath', 'date_of_birth' => '1978-08-21',
         'gender' => 'Male', 'occupation' => 'Hotel manager', 'mobile_number' => '9845663201',
         'id_number' => 'NDDPK9018H', 'house_number' => 'Kamath Bhavan, 3-45',
         'street' => 'Balmatta Road', 'city' => 'Mangaluru', 'state' => 'Karnataka', 'pin_code' => '575003'],

        ['product' => 'stove', 'full_name' => 'Fatima Sheikh', 'date_of_birth' => '1990-11-19',
         'gender' => 'Female', 'occupation' => 'Tailor', 'mobile_number' => '9909774512',
         'id_number' => 'OEEPS2260J', 'house_number' => '7, Ghogha Circle',
         'street' => 'Waghawadi Road', 'city' => 'Bhavnagar', 'state' => 'Gujarat', 'pin_code' => '364002'],

        ['product' => 'stove', 'full_name' => 'Deepak Rawat', 'date_of_birth' => '1984-03-04',
         'gender' => 'Male', 'occupation' => 'Guest house owner', 'mobile_number' => '9412009338',
         'id_number' => 'PFFPR5583K', 'house_number' => '22, Rajpur Road',
         'street' => 'Dilaram Bazaar', 'city' => 'Dehradun', 'state' => 'Uttarakhand', 'pin_code' => '248001'],

        ['product' => 'stove', 'full_name' => 'Anjali Mishra', 'date_of_birth' => '1995-07-26',
         'gender' => 'Female', 'occupation' => 'Postgraduate student', 'mobile_number' => '9838215407',
         'id_number' => 'QGGPM8816L', 'house_number' => 'B-31/44, Lanka',
         'street' => 'Ravindrapuri Colony', 'city' => 'Varanasi', 'state' => 'Uttar Pradesh', 'pin_code' => '221010'],

        ['product' => 'stove', 'full_name' => 'Joseph Fernandes', 'date_of_birth' => '1975-12-15',
         'gender' => 'Male', 'occupation' => 'Retired customs officer', 'mobile_number' => '9822104477',
         'id_number' => 'RHHPF4402M', 'house_number' => 'Villa Rosa, 18th June Road',
         'street' => 'Miramar', 'city' => 'Panaji', 'state' => 'Goa', 'pin_code' => '403001'],

        ['product' => 'stove', 'full_name' => 'Swapna Sahu', 'date_of_birth' => '1989-09-09',
         'gender' => 'Female', 'occupation' => 'Anganwadi supervisor', 'mobile_number' => '9437660189',
         'id_number' => 'SIIPS6673N', 'house_number' => 'Plot 419, Sahid Nagar',
         'street' => 'Janpath', 'city' => 'Bhubaneswar', 'state' => 'Odisha', 'pin_code' => '751014'],

        ['product' => 'stove', 'full_name' => 'Neelam Bisht', 'date_of_birth' => '1992-04-02',
         'gender' => 'Female', 'occupation' => 'Homestay host', 'mobile_number' => '9557331920',
         'id_number' => 'TJJPB1129P', 'house_number' => 'Pahadi Kothi, Kathgodam Road',
         'street' => 'Mukhani', 'city' => 'Haldwani', 'state' => 'Uttarakhand', 'pin_code' => '263139'],

        /* ---- tuktuk ---- */
        ['product' => 'tuktuk', 'full_name' => 'Gurpreet Sandhu', 'date_of_birth' => '1982-06-27',
         'gender' => 'Male', 'occupation' => 'Auto rickshaw driver', 'mobile_number' => '9814227706',
         'id_number' => 'UKKPS3348Q', 'house_number' => '61, Ranjit Avenue Block B',
         'street' => 'Batala Road', 'city' => 'Amritsar', 'state' => 'Punjab', 'pin_code' => '143001'],

        ['product' => 'tuktuk', 'full_name' => 'Mohan Lal', 'date_of_birth' => '1976-01-31',
         'gender' => 'Male', 'occupation' => 'Owner-driver', 'mobile_number' => '9414556093',
         'id_number' => 'VLLPL7791R', 'house_number' => '14, Gangashahar Road',
         'street' => 'Rani Bazaar', 'city' => 'Bikaner', 'state' => 'Rajasthan', 'pin_code' => '334001'],

        ['product' => 'tuktuk', 'full_name' => 'Selvam Murugan', 'date_of_birth' => '1987-11-08',
         'gender' => 'Male', 'occupation' => 'Auto rickshaw driver', 'mobile_number' => '9442310588',
         'id_number' => 'WMMPM2205S', 'house_number' => '9/3, Sellur',
         'street' => 'Alagarkoil Road', 'city' => 'Madurai', 'state' => 'Tamil Nadu', 'pin_code' => '625002'],

        ['product' => 'tuktuk', 'full_name' => 'Abdul Rahman', 'date_of_birth' => '1980-04-16',
         'gender' => 'Male', 'occupation' => 'Transport agent', 'mobile_number' => '9846007731',
         'id_number' => 'XNNPR6650T', 'house_number' => 'Rahmath Manzil, 24/117',
         'street' => 'Mavoor Road', 'city' => 'Kozhikode', 'state' => 'Kerala', 'pin_code' => '673004'],

        ['product' => 'tuktuk', 'full_name' => 'Prakash Jha', 'date_of_birth' => '1985-08-23',
         'gender' => 'Male', 'occupation' => 'Fleet co-ordinator', 'mobile_number' => '9931224087',
         'id_number' => 'YOOPJ9902V', 'house_number' => 'H. No. 88, Kankarbagh',
         'street' => 'Lohia Nagar', 'city' => 'Patna', 'state' => 'Bihar', 'pin_code' => '800020'],

        ['product' => 'tuktuk', 'full_name' => 'Bhavesh Solanki', 'date_of_birth' => '1991-03-11',
         'gender' => 'Male', 'occupation' => 'Owner-driver', 'mobile_number' => '9825440176',
         'id_number' => 'ZPPPS4417W', 'house_number' => '18, Kalawad Road',
         'street' => 'Amin Marg', 'city' => 'Rajkot', 'state' => 'Gujarat', 'pin_code' => '360005'],

        ['product' => 'tuktuk', 'full_name' => 'Ramesh Naik', 'date_of_birth' => '1979-07-05',
         'gender' => 'Male', 'occupation' => 'Garage owner', 'mobile_number' => '9448329015',
         'id_number' => 'AQQPN8834X', 'house_number' => '55, Vidyanagar',
         'street' => 'Gokul Road', 'city' => 'Hubballi', 'state' => 'Karnataka', 'pin_code' => '580020'],

        ['product' => 'tuktuk', 'full_name' => 'Sunil Gaikwad', 'date_of_birth' => '1988-12-02',
         'gender' => 'Male', 'occupation' => 'Auto rickshaw driver', 'mobile_number' => '9960117348',
         'id_number' => 'BRRPG1160Y', 'house_number' => '3, Cidco N-4',
         'street' => 'Jalna Road', 'city' => 'Aurangabad', 'state' => 'Maharashtra', 'pin_code' => '431005'],

        ['product' => 'tuktuk', 'full_name' => 'Jitender Malik', 'date_of_birth' => '1983-05-20',
         'gender' => 'Male', 'occupation' => 'Contract driver', 'mobile_number' => '9812665402',
         'id_number' => 'CSSPM5586Z', 'house_number' => '712, Sector 14',
         'street' => 'Delhi Bypass Road', 'city' => 'Rohtak', 'state' => 'Haryana', 'pin_code' => '124001'],

        ['product' => 'tuktuk', 'full_name' => 'Tapan Ghosh', 'date_of_birth' => '1977-09-14',
         'gender' => 'Male', 'occupation' => 'Owner-driver', 'mobile_number' => '9832009661',
         'id_number' => 'DTTPG3013A', 'house_number' => '41, Ashrampara',
         'street' => 'Hill Cart Road', 'city' => 'Siliguri', 'state' => 'West Bengal', 'pin_code' => '734001'],
    ];
}

/**
 * Fill in whatever a person did not say, from the values the form itself offers.
 * Everything rotates on $index, so no two records in a row read alike and the
 * lists have something to sort and filter by.
 */
function dummy_expand(array $person, int $index): array
{
    $stove = $person['product'] === 'stove';

    $pools = [
        'property_type' => $stove
            ? ['Apartment', 'Villa', 'Town House', 'Farm House', 'Commercial Kitchen', 'Institutional Kitchen']
            : ['Individual Owner-Driver', 'Fleet Operator', 'Commercial'],
        'ownership_status' => ['Owner', 'Owner', 'Tenant'],
        'existing_fuel' => $stove
            ? ['LPG', 'Piped Natural Gas', 'Kerosene', 'Firewood', 'Induction', 'Electric']
            : ['CNG', 'Petrol', 'Diesel', 'Auto Engine'],
        'intended_usage' => $stove
            ? ['Personal', 'Personal', 'Commercial', 'Institutional']
            : ['Personal', 'Commercial', 'Commercial', 'Fleet'],
        'expected_daily_usage' => $stove
            ? ['1–2 hours', '2–3 hours', '3–4 hours', '5–6 hours', '8 hours']
            : ['6–7 hours', '8–9 hours', '10–12 hours', '14 hours across two shifts'],
        'water_source' => ['Municipal Water', 'Borewell', 'Well Water'],
        'monthly_gas_consumption' => $stove
            ? ['1 cylinder (14.2 kg)', '2 cylinders a month', '14 SCM piped gas', '22 SCM piped gas', 'Firewood, bought weekly']
            : ['₹5,800 of CNG', '₹7,400 of petrol', '₹11,200 of diesel', '₹19,500 of CNG across two autos'],
        'monthly_electric_consumption' => ['135 units', '180 units', '230 units', '310 units', '460 units'],
        'carbon_interest' => ['High', 'Medium', 'High', 'Low'],
        'payment_method' => ['Full Payment', 'Full Payment', 'Installments', 'Lease-to-Own'],
        'bank_name' => ['State Bank of India', 'HDFC Bank', 'ICICI Bank', 'Bank of Baroda', 'Kotak Mahindra Bank',
                        'Union Bank of India', 'Canara Bank', 'IDFC First Bank'],
        'referral_source' => ['Social Media', 'Advertisement', 'Friend / Family', 'Distributor',
                              'Exhibition', 'Website', 'Other'],
    ];

    foreach ($pools as $field => $values) {
        if (!isset($person[$field])) {
            $person[$field] = $values[$index % count($values)];
        }
    }

    $person['household_members']  = $person['household_members']  ?? (2 + $index % 7);
    $person['units_required']     = $person['units_required']     ?? (1 + $index % 3);
    $person['alt_mobile_number']  = $person['alt_mobile_number']  ?? ($index % 3 === 0 ? '' : substr($person['mobile_number'], 0, 9) . (($index + 4) % 10));

    /* only the two financed methods carry a plan */
    $person['financing_option'] = $person['financing_option'] ?? [
        'Full Payment' => '',
        'Installments' => ['Three monthly instalments', 'Six monthly instalments', 'Nine monthly instalments'][$index % 3],
        'Lease-to-Own' => ['Twelve month lease', 'Eighteen month lease'][$index % 2],
    ][$person['payment_method']];

    /* newest last, roughly two a week going back through the year */
    $person['days_ago'] = $person['days_ago'] ?? (58 - $index * 3);

    $person['email'] = $person['email'] ?? strtolower(
        str_replace(' ', '.', $person['full_name'])
    ) . DUMMY_DOMAIN;

    return $person;
}

/** Ten enquiries through the contact form. */
function dummy_contacts(): array
{
    return [
        ['name' => 'Nikhil Menon', 'company' => 'Menon Hospitality LLP',
         'email' => 'nikhil.menon' . DUMMY_DOMAIN, 'phone' => '9846112097',
         'interest' => 'stove', 'city' => 'Thrissur',
         'message' => "We run four restaurant kitchens in Thrissur and are looking at replacing our LPG burners.\n\nCould someone give us a rough idea of what a commercial installation costs and how long the changeover takes? Happy to host a site visit any weekday.",
         'status' => 'contacted', 'note' => 'Called back — commercial kitchen quote sent.', 'days_ago' => 44],

        ['name' => 'Shalini Gupta', 'company' => '',
         'email' => 'shalini.gupta' . DUMMY_DOMAIN, 'phone' => '9711220844',
         'interest' => 'stove', 'city' => 'New Delhi',
         'message' => 'Is the stove available in Delhi yet, and does it need its own water connection in the kitchen? We are a family of five.',
         'status' => 'accepted', 'note' => '', 'days_ago' => 39],

        ['name' => 'Arjun Pillai', 'company' => 'Pillai Auto Services',
         'email' => 'arjun.pillai' . DUMMY_DOMAIN, 'phone' => '9895443310',
         'interest' => 'tuktuk', 'city' => 'Thiruvananthapuram',
         'message' => "I service around forty autos a month and drivers keep asking about hydrogen conversion.\n\nDo you train and certify workshops to fit the kit? I would like to be the first in the district.",
         'status' => 'contacted', 'note' => 'Workshop certification is not open yet — noted for the pilot.', 'days_ago' => 35],

        ['name' => 'Farhan Shaikh', 'company' => 'Sabari Fleet Movers',
         'email' => 'farhan.shaikh' . DUMMY_DOMAIN, 'phone' => '9820561774',
         'interest' => 'fleet', 'city' => 'Mumbai',
         'message' => 'We operate 120 three-wheelers across Mumbai and Thane. Looking for per-unit pricing at that volume, and what the refuelling arrangement would be for a depot.',
         'status' => 'accepted', 'note' => 'Fleet pricing sheet requested from the MD.', 'days_ago' => 31],

        ['name' => 'Lakshmi Subramanian', 'company' => 'Green Hearth Distributors',
         'email' => 'lakshmi.subramanian' . DUMMY_DOMAIN, 'phone' => '9962007745',
         'interest' => 'distribution', 'city' => 'Coimbatore',
         'message' => 'We distribute kitchen appliances across western Tamil Nadu. Are distributor appointments open, and what is the minimum order?',
         'status' => 'new', 'note' => '', 'days_ago' => 26],

        ['name' => 'Devika Rao', 'company' => '',
         'email' => 'devika.rao' . DUMMY_DOMAIN, 'phone' => '9900871263',
         'interest' => 'stove', 'city' => 'Mysuru',
         'message' => 'How much water does the stove use in a day of normal cooking? We are on a borewell and want to be sure before applying.',
         'status' => 'new', 'note' => '', 'days_ago' => 22],

        ['name' => 'Tarun Bhatia', 'company' => 'Bhatia Energy Advisors',
         'email' => 'tarun.bhatia' . DUMMY_DOMAIN, 'phone' => '9873445621',
         'interest' => 'partnership', 'city' => 'Gurugram',
         'message' => "We advise two state transport undertakings on fuel transition and would like to talk about a pilot.\n\nIs there a technical dossier you can share under an NDA?",
         'status' => 'new', 'note' => '', 'days_ago' => 17],

        ['name' => 'Ritu Kulkarni', 'company' => 'Sahyadri Women Collective',
         'email' => 'ritu.kulkarni' . DUMMY_DOMAIN, 'phone' => '9764330128',
         'interest' => 'other', 'city' => 'Nashik',
         'message' => 'We work with 300 rural households on clean cooking. Is there a subsidised or CSR-funded route for a group like ours?',
         'status' => 'new', 'note' => '', 'days_ago' => 12],

        ['name' => 'Suresh Patil', 'company' => '',
         'email' => 'suresh.patil' . DUMMY_DOMAIN, 'phone' => '9403119857',
         'interest' => 'tuktuk', 'city' => 'Kolhapur',
         'message' => 'My auto is a 2016 CNG model. Can it be converted, and does the RTO have to approve it afterwards?',
         'status' => 'new', 'note' => '', 'days_ago' => 7],

        ['name' => 'Promo Desk', 'company' => '',
         'email' => 'promo.blast' . DUMMY_DOMAIN, 'phone' => '0000000000',
         'interest' => 'other', 'city' => '',
         'message' => 'BUY BACKLINKS CHEAP RANK NUMBER ONE ON GOOGLE GUARANTEED CONTACT US NOW',
         'status' => 'rejected', 'note' => 'Spam.', 'days_ago' => 3],
    ];
}

/** Ten newsletter signups, from the pages the footer form sits on. */
function dummy_subscribers(): array
{
    return [
        ['email' => 'aarav.joshi' . DUMMY_DOMAIN,    'source_page' => 'index.html',        'status' => 'accepted',  'days_ago' => 48],
        ['email' => 'sneha.kulkarni' . DUMMY_DOMAIN, 'source_page' => 'stove.html',        'status' => 'accepted',  'days_ago' => 43],
        ['email' => 'rahul.verma' . DUMMY_DOMAIN,    'source_page' => 'tuktuk.html',       'status' => 'new',       'days_ago' => 38],
        ['email' => 'ishita.das' . DUMMY_DOMAIN,     'source_page' => 'technology.html',   'status' => 'new',       'days_ago' => 34],
        ['email' => 'karthik.rajan' . DUMMY_DOMAIN,  'source_page' => 'blog.html',         'status' => 'contacted', 'days_ago' => 29],
        ['email' => 'pooja.mehta' . DUMMY_DOMAIN,    'source_page' => 'index.html',        'status' => 'new',       'days_ago' => 23],
        ['email' => 'ayaan.khan' . DUMMY_DOMAIN,     'source_page' => 'contact.html',      'status' => 'new',       'days_ago' => 18],
        ['email' => 'nandini.pai' . DUMMY_DOMAIN,    'source_page' => 'apply-stove.html',  'status' => 'new',       'days_ago' => 13],
        ['email' => 'harpreet.kaur' . DUMMY_DOMAIN,  'source_page' => 'apply-tuktuk.html', 'status' => 'new',       'days_ago' => 8],
        ['email' => 'noreply.bot' . DUMMY_DOMAIN,    'source_page' => 'index.html',        'status' => 'rejected',  'days_ago' => 2],
    ];
}

/** Ten more enquiries — a second week of post. */
function dummy_contacts_two(): array
{
    return [
        ['name' => 'Girish Hegde', 'company' => 'Hegde Caterers',
         'email' => 'girish.hegde' . DUMMY_DOMAIN, 'phone' => '9448771230',
         'interest' => 'stove', 'city' => 'Shivamogga',
         'message' => 'We cater weddings for 400 to 600 people and run eight burners at a time. Would the stove hold up to that, and can it run off a water tanker on site?',
         'status' => 'accepted', 'note' => 'Worth a demo — large outdoor catering is a new case for us.', 'days_ago' => 47],

        ['name' => 'Ravindra Apte', 'company' => 'Sanskruti Co-operative Housing Society',
         'email' => 'ravindra.apte' . DUMMY_DOMAIN, 'phone' => '9823006654',
         'interest' => 'stove', 'city' => 'Thane',
         'message' => "Our housing society has 84 flats and the managing committee has asked me to look into a bulk order.\n\nWould you do a demonstration in the society hall if we gather thirty or so families?",
         'status' => 'contacted', 'note' => 'Society demo pencilled in for next month.', 'days_ago' => 42],

        ['name' => 'Zoya Ansari', 'company' => '',
         'email' => 'zoya.ansari' . DUMMY_DOMAIN, 'phone' => '9702118845',
         'interest' => 'tuktuk', 'city' => 'Aurangabad',
         'message' => 'My husband drives an auto and we are wondering what the conversion does to the resale value. Is there a warranty on the kit?',
         'status' => 'new', 'note' => '', 'days_ago' => 36],

        ['name' => 'Balaji Srinivasan', 'company' => 'Vel Logistics',
         'email' => 'balaji.srinivasan' . DUMMY_DOMAIN, 'phone' => '9840556217',
         'interest' => 'fleet', 'city' => 'Tiruchirappalli',
         'message' => 'Thirty-two goods autos doing last-mile delivery. What is the payback period against diesel at current prices, and do you have a case study we can read?',
         'status' => 'accepted', 'note' => 'Sent the fleet economics sheet.', 'days_ago' => 32],

        ['name' => 'Pallavi Deshpande', 'company' => 'Deshpande Home Appliances',
         'email' => 'pallavi.deshpande' . DUMMY_DOMAIN, 'phone' => '9975008812',
         'interest' => 'distribution', 'city' => 'Kolhapur',
         'message' => 'Two showrooms in Kolhapur and one in Sangli. Interested in stocking the stove — what are the margins and who handles installation?',
         'status' => 'new', 'note' => '', 'days_ago' => 27],

        ['name' => 'Ankit Saxena', 'company' => 'The Morning Register',
         'email' => 'ankit.saxena' . DUMMY_DOMAIN, 'phone' => '9891220046',
         'interest' => 'other', 'city' => 'Noida',
         'message' => "I write for a regional daily and would like to do a piece on hydrogen cooking in Indian kitchens.\n\nCould I speak to someone on the engineering side, and is a photograph of the unit available?",
         'status' => 'contacted', 'note' => 'Press enquiry — passed to the MD.', 'days_ago' => 21],

        ['name' => 'Rekha Chandran', 'company' => 'Amrita Institute of Technology',
         'email' => 'rekha.chandran' . DUMMY_DOMAIN, 'phone' => '9895117702',
         'interest' => 'partnership', 'city' => 'Coimbatore',
         'message' => 'Our mechanical department would like to run a final-year project on hydrogen-on-demand. Would you host two students for a semester?',
         'status' => 'new', 'note' => '', 'days_ago' => 16],

        ['name' => 'Dinesh Bhandari', 'company' => '',
         'email' => 'dinesh.bhandari' . DUMMY_DOMAIN, 'phone' => '9829440178',
         'interest' => 'stove', 'city' => 'Udaipur',
         'message' => 'I run a small guest house with six rooms. Does the stove need servicing, and is anybody in Udaipur trained to do it?',
         'status' => 'new', 'note' => '', 'days_ago' => 11],

        ['name' => 'Meenakshi Iyer', 'company' => 'Sakhi Self Help Group',
         'email' => 'meenakshi.iyer' . DUMMY_DOMAIN, 'phone' => '9445660093',
         'interest' => 'other', 'city' => 'Vellore',
         'message' => 'Forty women in our group cook and sell food from home. Is there any instalment scheme small enough for us?',
         'status' => 'new', 'note' => '', 'days_ago' => 6],

        ['name' => 'SEO Growth Team', 'company' => '',
         'email' => 'seo.growth' . DUMMY_DOMAIN, 'phone' => '0000000000',
         'interest' => 'other', 'city' => '',
         'message' => 'HELLO SIR WE CAN INCREASE YOUR TRAFFIC 500 PERCENT REPLY FOR PRICE LIST',
         'status' => 'rejected', 'note' => 'Spam.', 'days_ago' => 1],
    ];
}

/** Ten more newsletter signups. */
function dummy_subscribers_two(): array
{
    return [
        ['email' => 'vivek.nambiar' . DUMMY_DOMAIN,   'source_page' => 'technology.html',   'status' => 'accepted',  'days_ago' => 50],
        ['email' => 'shruti.agarwal' . DUMMY_DOMAIN,  'source_page' => 'index.html',        'status' => 'new',       'days_ago' => 45],
        ['email' => 'imtiaz.bhat' . DUMMY_DOMAIN,     'source_page' => 'stove.html',        'status' => 'new',       'days_ago' => 40],
        ['email' => 'lata.pawar' . DUMMY_DOMAIN,      'source_page' => 'blog.html',         'status' => 'contacted', 'days_ago' => 35],
        ['email' => 'george.mathew' . DUMMY_DOMAIN,   'source_page' => 'tuktuk.html',       'status' => 'new',       'days_ago' => 30],
        ['email' => 'bhavna.trivedi' . DUMMY_DOMAIN,  'source_page' => 'contact.html',      'status' => 'accepted',  'days_ago' => 25],
        ['email' => 'ompraksh.tiwari' . DUMMY_DOMAIN, 'source_page' => 'coming-soon.html',  'status' => 'new',       'days_ago' => 20],
        ['email' => 'aisha.qadri' . DUMMY_DOMAIN,     'source_page' => 'apply-tuktuk.html', 'status' => 'new',       'days_ago' => 15],
        ['email' => 'sudhir.rane' . DUMMY_DOMAIN,     'source_page' => 'privacy-policy.html', 'status' => 'new',     'days_ago' => 10],
        ['email' => 'crawler.bot' . DUMMY_DOMAIN,     'source_page' => 'index.html',        'status' => 'rejected',  'days_ago' => 4],
    ];
}

/**
 * A hundred more signups, for seeing how a long list behaves — twelve pages of
 * it, once the twenty written by hand are counted.
 *
 * Ten first names against ten surnames walks a grid of exactly a hundred, so
 * every address is different and none of them collides with the twenty above,
 * which share no first name with this list.
 */
function dummy_subscribers_bulk(): array
{
    $firsts = ['amit', 'kiran', 'neha', 'rohan', 'tanvi', 'varun', 'isha', 'manav', 'ritika', 'sameer'];
    $lasts  = ['shetty', 'chatterjee', 'bansal', 'gowda', 'dubey', 'kaul', 'thakur', 'pandey', 'borkar', 'venkatesh'];

    /* every page the footer form sits on */
    $pages = ['index.html', 'stove.html', 'tuktuk.html', 'technology.html', 'blog.html',
              'contact.html', 'apply-stove.html', 'apply-tuktuk.html', 'coming-soon.html'];

    /* six in ten are still untouched, which is what a real list looks like */
    $states = ['new', 'new', 'new', 'new', 'new', 'new', 'accepted', 'accepted', 'contacted', 'rejected'];

    $rows = [];

    for ($index = 0; $index < 100; $index++) {
        $rows[] = [
            'email'       => $firsts[$index % 10] . '.' . $lasts[intdiv($index, 10)] . DUMMY_DOMAIN,
            'source_page' => $pages[$index % count($pages)],
            'status'      => $states[$index % count($states)],
            /* two days apart, so the newest-first order is worth paging through */
            'days_ago'    => 2 + $index * 2,
        ];
    }

    return $rows;
}

/** An address in the range set aside for documentation, so it is plainly fake. */
function dummy_ip(): string
{
    return '203.0.113.' . random_int(2, 250);
}

/** Somewhere inside the working day, that many days ago. */
function dummy_when(int $daysAgo): string
{
    return date('Y-m-d H:i:s', strtotime('-' . $daysAgo . ' days +' . random_int(9, 18) . ' hours'));
}

/* --------------------------------------------------------------------------
 * Remove
 * ----------------------------------------------------------------------- */

if (in_array('--remove', $argv, true)) {
    $gone = 0;

    /* payments and raffle places hang off an application and go with it */
    foreach (['applications', 'contact_messages', 'newsletter_subscribers'] as $table) {
        $stmt = db()->prepare('DELETE FROM ' . $table . ' WHERE email LIKE ?');
        $stmt->execute(['%' . DUMMY_DOMAIN]);

        echo str_pad($table, 24), $stmt->rowCount(), " removed\n";
        $gone += $stmt->rowCount();
    }

    echo "\n", $gone === 0 ? 'Nothing to remove — no row uses ' . DUMMY_DOMAIN . '.' : $gone . ' gone.', "\n";
    exit;
}

/* --------------------------------------------------------------------------
 * Add
 * ----------------------------------------------------------------------- */

echo "Applications\n";

$exists  = db()->prepare('SELECT id FROM applications WHERE email = ?');
$added   = 0;
$skipped = 0;

$people = dummy_people();

foreach (dummy_batch_two() as $index => $person) {
    $people[] = dummy_expand($person, $index);
}

foreach ($people as $person) {
    $exists->execute([$person['email']]);

    if ($exists->fetchColumn()) {
        echo 'skipped ', $person['full_name'], " — already there\n";
        $skipped++;
        continue;
    }

    $when = dummy_when($person['days_ago']);

    $columns = [
        'product'                      => $person['product'],
        'status'                       => 'payment_pending',
        'referral_code'                => make_referral_code(),
        'full_name'                    => $person['full_name'],
        'date_of_birth'                => $person['date_of_birth'],
        'nationality'                  => 'Indian',
        'gender'                       => $person['gender'],
        'occupation'                   => $person['occupation'],
        'mobile_number'                => $person['mobile_number'],
        'alt_mobile_number'            => $person['alt_mobile_number'] !== '' ? $person['alt_mobile_number'] : null,
        'email'                        => $person['email'],
        'id_number'                    => $person['id_number'],
        'house_number'                 => $person['house_number'],
        'street'                       => $person['street'],
        'city'                         => $person['city'],
        'state'                        => $person['state'],
        'country'                      => 'India',
        'pin_code'                     => $person['pin_code'],
        'property_type'                => $person['property_type'],
        'ownership_status'             => $person['ownership_status'],
        'household_members'            => $person['household_members'],
        'existing_fuel'                => $person['existing_fuel'],
        'units_required'               => $person['units_required'],
        'intended_usage'               => $person['intended_usage'],
        'expected_daily_usage'         => $person['expected_daily_usage'],
        'preferred_install_date'       => date('Y-m-d', strtotime($when . ' +30 days')),
        'water_source'                 => $person['water_source'],
        'continuous_water'             => 'yes',
        'water_storage'                => 'yes',
        'dedicated_kitchen'            => 'yes',
        'countertop_space'             => 'yes',
        'existing_gas'                 => 'yes',
        'existing_electric'            => 'yes',
        'payment_method'               => $person['payment_method'],
        'financing_option'             => $person['financing_option'] !== '' ? $person['financing_option'] : null,
        'bank_name'                    => $person['bank_name'],
        'referral_source'              => $person['referral_source'],
        'monthly_gas_consumption'      => $person['monthly_gas_consumption'],
        'monthly_electric_consumption' => $person['monthly_electric_consumption'],
        'carbon_interest'              => $person['carbon_interest'],
        'declaration_accepted'         => 1,
        'testimonial_consent'          => 1,
        'terms_accepted'               => 1,
        'payment_amount'               => PAYMENT_AMOUNT,
        'admin_note'                   => 'Test record added by admin/seed-dummy.php.',
        'ip_address'                   => dummy_ip(),
        'created_at'                   => $when,
    ];

    $names = array_keys($columns);
    $sql   = 'INSERT INTO applications (`' . implode('`, `', $names) . '`) VALUES ('
           . implode(', ', array_fill(0, count($names), '?')) . ')';

    db()->prepare($sql)->execute(array_values($columns));

    $id = (int) db()->lastInsertId();

    db()->prepare('UPDATE applications SET reference_code = ? WHERE id = ?')
        ->execute([make_reference_code($id), $id]);

    /* paid in full, verified the next day — the office recorded the transfer */
    $app = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $app->execute([$id]);
    $row = $app->fetch();

    $paidAt = date('Y-m-d H:i:s', strtotime($when . ' +1 day'));

    db()->prepare(
        'INSERT INTO payments (application_id, amount, reference, status, receipt_no, uploaded_at, decided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        PAYMENT_AMOUNT,
        'DUMMY-UPI-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
        'verified',
        next_receipt_no($row),
        $paidAt,
        $paidAt,
    ]);

    /* let the real rule decide the status, rather than writing 'complete' */
    $status = sync_application_status($id);

    echo 'added   ', str_pad($person['full_name'], 18), ' ', str_pad($person['product'], 7),
        ' ', $person['city'], ' — ', $status, "\n";
    $added++;
}

echo $added, ' added, ', $skipped, " skipped.\n";

/* --------------------------------------------------------------------------
 * Contact enquiries
 * ----------------------------------------------------------------------- */

echo "\nContact enquiries\n";

$exists = db()->prepare('SELECT id FROM contact_messages WHERE email = ?');
$insert = db()->prepare(
    'INSERT INTO contact_messages
        (status, name, company, email, phone, interest, city, message, consent, admin_note, ip_address, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$contactsAdded = 0;
$contactsSkipped = 0;

foreach (array_merge(dummy_contacts(), dummy_contacts_two()) as $enquiry) {
    $exists->execute([$enquiry['email']]);

    if ($exists->fetchColumn()) {
        echo 'skipped ', $enquiry['name'], " — already there\n";
        $contactsSkipped++;
        continue;
    }

    $insert->execute([
        $enquiry['status'],
        $enquiry['name'],
        $enquiry['company'] !== '' ? $enquiry['company'] : null,
        $enquiry['email'],
        $enquiry['phone'],
        $enquiry['interest'],
        $enquiry['city'] !== '' ? $enquiry['city'] : null,
        $enquiry['message'],
        $enquiry['status'] === 'rejected' ? 0 : 1,
        $enquiry['note'] !== '' ? $enquiry['note'] : null,
        dummy_ip(),
        dummy_when($enquiry['days_ago']),
    ]);

    echo 'added   ', str_pad($enquiry['name'], 21), ' ', str_pad($enquiry['interest'], 13),
        ' ', $enquiry['status'], "\n";
    $contactsAdded++;
}

echo $contactsAdded, ' added, ', $contactsSkipped, " skipped.\n";

/* --------------------------------------------------------------------------
 * Newsletter signups
 * ----------------------------------------------------------------------- */

echo "\nNewsletter signups\n";

/* the email is unique on this table, so a repeat run updates rather than doubles */
$insert = db()->prepare(
    'INSERT INTO newsletter_subscribers (status, email, source_page, ip_address, created_at)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE source_page = VALUES(source_page)'
);

$subsAdded = 0;
$subsSkipped = 0;

foreach (array_merge(dummy_subscribers(), dummy_subscribers_two(), dummy_subscribers_bulk()) as $subscriber) {
    $insert->execute([
        $subscriber['status'],
        $subscriber['email'],
        $subscriber['source_page'],
        dummy_ip(),
        dummy_when($subscriber['days_ago']),
    ]);

    /* rowCount is 1 on an insert and 0 or 2 when the row was already there */
    if ($insert->rowCount() === 1) {
        $subsAdded++;
    } else {
        $subsSkipped++;
    }
}

/* there are too many of these to name one by one */
echo $subsAdded, ' added, ', $subsSkipped, " skipped.\n";

/* --------------------------------------------------------------------------
 * Where that leaves things
 * ----------------------------------------------------------------------- */

echo "\nEligible for the raffle now: ", (int) db()->query(
    "SELECT COUNT(*) FROM applications a
  LEFT JOIN raffle_winners w ON w.application_id = a.id
      WHERE a.status = 'complete' AND w.id IS NULL"
)->fetchColumn(), "\n";
echo "Waiting on the office: ", (int) db()->query(
    "SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"
)->fetchColumn(), " enquiries, ", (int) db()->query(
    "SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'new'"
)->fetchColumn(), " signups\n";
echo "Remove them all again with: php admin/seed-dummy.php --remove\n";
