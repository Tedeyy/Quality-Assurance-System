/**
 * Philippine Address Selector using PSGC API
 */
document.addEventListener('DOMContentLoaded', async () => {
    const provinceSelect = document.getElementById('province');
    const citySelect = document.getElementById('city');
    const barangaySelect = document.getElementById('barangay');

    // Only run if the elements exist (Profile Gate is active)
    if (!provinceSelect || !citySelect || !barangaySelect) return;

    // Prevent scrolling when modal is open
    document.body.style.overflow = 'hidden';

    // Helper to sort alphabetically by name
    const sortByName = (a, b) => a.name.localeCompare(b.name);

    try {
        // 1. Fetch Provinces
        const provResponse = await fetch('https://psgc.gitlab.io/api/provinces/');
        let provinces = await provResponse.json();

        // Fetch NCR Districts (since Metro Manila is not a province)
        const ncrResponse = await fetch('https://psgc.gitlab.io/api/regions/130000000/districts/');
        const ncrDistricts = await ncrResponse.json();

        // Combine and sort
        const allProvinces = [...provinces, ...ncrDistricts].sort(sortByName);

        allProvinces.forEach(p => {
            const option = document.createElement('option');
            option.value = p.name;
            option.textContent = p.name;
            option.dataset.code = p.code;
            option.dataset.isDistrict = (p.regionCode === "130000000");
            provinceSelect.appendChild(option);
        });

        // 2. Handle Province Change
        provinceSelect.addEventListener('change', async function () {
            citySelect.innerHTML = '<option value="">Loading...</option>';
            citySelect.disabled = true;
            barangaySelect.innerHTML = '<option value="">Select Barangay...</option>';
            barangaySelect.disabled = true;

            const selectedOption = this.options[this.selectedIndex];
            if (!selectedOption.value) {
                citySelect.innerHTML = '<option value="">Select City...</option>';
                return;
            }

            const code = selectedOption.dataset.code;
            const isDistrict = selectedOption.dataset.isDistrict === "true";

            // Fetch Cities
            const endpoint = isDistrict
                ? `https://psgc.gitlab.io/api/districts/${code}/cities-municipalities/`
                : `https://psgc.gitlab.io/api/provinces/${code}/cities-municipalities/`;

            const cityResponse = await fetch(endpoint);
            const cities = await cityResponse.json();
            cities.sort(sortByName);

            citySelect.innerHTML = '<option value="">Select City...</option>';
            cities.forEach(c => {
                const option = document.createElement('option');
                option.value = c.name;
                option.textContent = c.name;
                option.dataset.code = c.code;
                citySelect.appendChild(option);
            });
            citySelect.disabled = false;
        });

        // 3. Handle City Change
        citySelect.addEventListener('change', async function () {
            barangaySelect.innerHTML = '<option value="">Loading...</option>';
            barangaySelect.disabled = true;

            const selectedOption = this.options[this.selectedIndex];
            if (!selectedOption.value) {
                barangaySelect.innerHTML = '<option value="">Select Barangay...</option>';
                return;
            }

            const code = selectedOption.dataset.code;

            // Fetch Barangays
            const brgyResponse = await fetch(`https://psgc.gitlab.io/api/cities-municipalities/${code}/barangays/`);
            const barangays = await brgyResponse.json();
            barangays.sort(sortByName);

            barangaySelect.innerHTML = '<option value="">Select Barangay...</option>';
            barangays.forEach(b => {
                const option = document.createElement('option');
                option.value = b.name;
                option.textContent = b.name;
                barangaySelect.appendChild(option);
            });
            barangaySelect.disabled = false;
        });

    } catch (error) {
        console.error("Error loading PSGC data:", error);
        provinceSelect.innerHTML = '<option value="">Error loading locations. Please refresh.</option>';
    }
});
