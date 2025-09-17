// cashier.js

// Load bills when page loads
document.addEventListener("DOMContentLoaded", () => {
    loadBills();

    // Handle payment form submission
    document.getElementById("paymentForm").onsubmit = async (e) => {
        e.preventDefault();

        const payload = {
            bill_id: document.getElementById("billId").value,
            amount_paid: parseFloat(document.getElementById("amountPaid").value),
            payment_method: document.getElementById("paymentMethod").value
        };

        if (!payload.amount_paid || payload.amount_paid <= 0) {
            alert("Please enter a valid amount.");
            return;
        }

        try {
            const res = await fetch("php/cashier.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });

            const result = await res.json();
            if (result.success) {
                alert("Payment processed successfully!");
                closePaymentModal();
                loadBills();
            } else {
                alert("Error: " + result.message);
            }
        } catch (err) {
            console.error("Payment error:", err);
            alert("An error occurred while processing payment.");
        }
    };
});

// Load bills from backend
async function loadBills() {
    try {
        const res = await fetch("php/cashier.php");
        if (res.status === 403) {
            window.location.href = "login.html"; // redirect if session expired
            return;
        }

        const bills = await res.json();
        const tbody = document.getElementById("billsBody");
        tbody.innerHTML = "";

        if (bills.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4">No bills found</td></tr>`;
            return;
        }

        bills.forEach(bill => {
            let statusText = "";
            let statusClass = "";
            let allowPayment = false;

            if (bill.booking_status === "completed") {
                statusText = "Completed";
                statusClass = "text-green-700";
                allowPayment = false;
            } else if (bill.total_amount <= bill.paid_amount) {
                statusText = "Paid";
                statusClass = "text-green-600";
                allowPayment = false;
            } else {
                statusText = "Pending";
                statusClass = "text-red-600";
                allowPayment = true;
            }

            const row = `
                <tr class="border-b">
                    <td class="px-6 py-3">${bill.guest_name}</td>
                    <td class="px-6 py-3">${bill.room_number}</td>
                    <td class="px-6 py-3">₱${bill.total_amount.toFixed(2)}</td>
                    <td class="px-6 py-3">₱${bill.paid_amount.toFixed(2)}</td>
                    <td class="px-6 py-3">${bill.payment_method}</td>
                    <td class="px-6 py-3 ${statusClass} font-semibold">${statusText}</td>
                    <td class="px-6 py-3">
                        ${allowPayment ?
                    `<button onclick="openPaymentModal(${bill.id}, ${bill.total_amount}, ${bill.paid_amount})"
                                class="bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700 transition">
                                Pay
                             </button>`
                    : "-"
                }
                    </td>
                </tr>
            `;
            tbody.insertAdjacentHTML("beforeend", row);
        });

    } catch (err) {
        console.error("Error loading bills:", err);
    }
}

// Open modal with bill details
function openPaymentModal(billId, totalAmount, paidAmount) {
    document.getElementById("billId").value = billId;
    document.getElementById("amountDue").value = `₱${totalAmount.toFixed(2)}`;
    document.getElementById("amountPaid").value = (totalAmount - paidAmount).toFixed(2); // default suggested payment
    document.getElementById("paymentMethod").value = "cash";
    document.getElementById("paymentModal").classList.remove("hidden");
}

// Close modal
function closePaymentModal() {
    document.getElementById("paymentModal").classList.add("hidden");
}
