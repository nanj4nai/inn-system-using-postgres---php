// Sidebar toggle
document.getElementById('menu-button').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('active');
});

// Modal controls
function openBookingModal() {
    document.getElementById('bookingModal').classList.remove('hidden');
}
function closeBookingModal() {
    document.getElementById('bookingModal').classList.add('hidden');

    // --- Reset modal inputs on close ---
    document.getElementById("bookingForm").reset();
    document.getElementById("pricePreview").value = "";
    document.getElementById("checkoutPreview").value = "";
    window.bookingCheckIn = null;
}

// --- Format date in Manila timezone ---
function formatDateTimeManila(date) {
    const options = {
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
        timeZone: "Asia/Manila"
    };
    return new Intl.DateTimeFormat("en-US", options).format(date).replace(",", " -");
}

// Elements
const guestSelect = document.getElementById("guestSelect");
const roomSelect = document.getElementById("roomSelect");
const tbody = document.getElementById("bookingsBody");

// --- Load data and populate table ---
async function loadData() {
    try {
        const [roomsRes, guestsRes, bookingsRes] = await Promise.all([
            fetch("php/rooms.php"),
            fetch("php/guests.php"),
            fetch("php/bookings.php")
        ]);

        const rooms = await roomsRes.json();
        const guests = await guestsRes.json();
        const bookings = await bookingsRes.json();

        // Populate guest select
        guestSelect.innerHTML = `<option value="">Select Guest</option>`;
        guests.forEach(g => guestSelect.innerHTML += `<option value="${g.id}">${g.full_name}</option>`);

        // Populate room select with disabled ongoing rooms
        roomSelect.innerHTML = `<option value="">Select Room</option>`;
        rooms.forEach(r => {
            const isOngoing = bookings.some(b => b.room_id == r.id && b.status === 'ongoing');
            roomSelect.innerHTML += `<option value="${r.id}" ${isOngoing ? "disabled" : ""}>${r.room_number} - ${r.room_type}${isOngoing ? " (Occupied)" : ""}</option>`;
        });

        // Populate bookings table
        tbody.innerHTML = "";
        bookings.forEach(b => {
            const checkIn = new Date(new Date(b.check_in).getTime() + 6 * 60 * 60 * 1000);
            const checkOut = new Date(checkIn.getTime() + b.expected_hours * 60 * 60 * 1000);

            const room = rooms.find(r => r.id == b.room_id);
            let total = 0;
            if (room) {
                const basePrice = parseFloat(room.base_price) || 0;
                const minHours = parseInt(room.min_hours) || 0;
                const extraPrice = parseFloat(room.extra_hour_price) || 0;
                total = basePrice;
                if (b.expected_hours > minHours) total += (b.expected_hours - minHours) * extraPrice;
            }

            tbody.innerHTML += `
                <tr class="border-t">
                    <td class="px-6 py-4">${b.guest}</td>
                    <td class="px-6 py-4">${b.room_number} (${b.room_type})</td>
                    <td class="px-6 py-4">${formatDateTimeManila(checkIn)}</td>
                    <td class="px-6 py-4">${b.expected_hours} hrs</td>
                    <td class="px-6 py-4">₱${total.toFixed(2)}</td>
                    <td class="px-6 py-4">${b.status}</td>
                    <td class="px-6 py-4">${formatDateTimeManila(checkOut)}</td>
                    <td class="px-6 py-4 space-x-3">
                        <button onclick="editBooking(${b.id})" class="text-yellow-600 hover:underline">
                            <i class="fa-solid fa-edit"></i> Edit
                        </button>
                        <button onclick="openServiceModal(${b.id})" class="text-green-600 hover:underline">
                            <i class="fa-solid fa-plus"></i> Add Service
                        </button>
                    </td>
                </tr>
            `;
        });

        window.roomsData = rooms;
    } catch (err) {
        console.error("Error loading data:", err);
    }
}

// --- Edit booking modal ---
async function editBooking(id) {
    const res = await fetch(`php/bookings.php?id=${id}`);
    if (res.status === 403) return window.location.href = "login.html";

    const booking = await res.json();
    if (!booking) return;

    window.bookingCheckIn = new Date(booking.check_in);

    document.getElementById("guestSelect").value = booking.guest_id;
    document.getElementById("roomSelect").value = booking.room_id;
    document.getElementById("hoursStay").value = booking.expected_hours;

    updateBookingPreview();
    openBookingModal();

    document.getElementById("bookingForm").onsubmit = async (e) => {
        e.preventDefault();
        const payload = {
            guest_id: parseInt(document.getElementById("guestSelect").value),
            room_id: parseInt(document.getElementById("roomSelect").value),
            expected_hours: parseInt(document.getElementById("hoursStay").value)
        };

        const updateRes = await fetch(`php/bookings.php?id=${id}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });

        if (updateRes.status === 403) return window.location.href = "login.html";

        closeBookingModal();
        loadData();
    };
}

// --- Update modal preview for checkout & price ---
function updateBookingPreview() {
    const roomId = parseInt(document.getElementById("roomSelect").value);
    const hours = parseInt(document.getElementById("hoursStay").value);
    const room = (window.roomsData || []).find(r => r.id == roomId);
    if (!room || !hours) return;

    const checkIn = window.bookingCheckIn || new Date();
    const checkout = new Date(checkIn.getTime() + hours * 60 * 60 * 1000);

    document.getElementById("checkoutPreview").value = formatDateTimeManila(checkout);

    const basePrice = parseFloat(room.base_price) || 0;
    const minHours = parseInt(room.min_hours) || 0;
    const extraPrice = parseFloat(room.extra_hour_price) || 0;
    let total = basePrice;
    if (hours > minHours) total += (hours - minHours) * extraPrice;

    document.getElementById("pricePreview").value = `₱${total.toFixed(2)}`;

    window.bookingCheckIn = checkIn;
}

function openServiceModal(bookingId) {
    document.getElementById("serviceModal").classList.remove("hidden");
    document.getElementById("serviceBookingId").value = bookingId;
    loadServices(); // populate dropdown
}

function closeServiceModal() {
    document.getElementById("serviceModal").classList.add("hidden");
    document.getElementById("serviceForm").reset();
}

async function loadServices() {
    const res = await fetch("php/services.php");
    const services = await res.json();
    const select = document.getElementById("serviceSelect");
    select.innerHTML = `<option value="">Select Service</option>`;
    services.forEach(s => {
        select.innerHTML += `<option value="${s.id}">${s.name} - ₱${s.price}</option>`;
    });
}

document.getElementById("serviceForm").onsubmit = async (e) => {
    e.preventDefault();
    const payload = {
        booking_id: parseInt(document.getElementById("serviceBookingId").value),
        service_id: parseInt(document.getElementById("serviceSelect").value),
        qty: parseInt(document.getElementById("serviceQty").value)
    };

    const res = await fetch("php/booking_services.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });

    if (res.status === 403) return window.location.href = "login.html";

    closeServiceModal();
    loadData(); // refresh bookings table with updated bill
};


// Event listeners for modal inputs
document.getElementById("hoursStay").addEventListener("input", updateBookingPreview);
document.getElementById("roomSelect").addEventListener("change", updateBookingPreview);

// Reset modal on close
document.getElementById("bookingModal").addEventListener("hidden", closeBookingModal);

// Load data on page ready
document.addEventListener("DOMContentLoaded", loadData);


