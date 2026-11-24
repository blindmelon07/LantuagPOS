import React, { useEffect, useState } from "react";
import { Modal, Button, Form } from "react-bootstrap";
import { useDispatch } from "react-redux";
import moment from "moment";
import apiConfig from "../../config/apiConfig";
import { addToast } from "../../store/action/toastAction";
import { toastType } from "../../constants";
import { useT, useMoney } from "../finance/financeShared";

const PAYMENT_TYPES = [
    { id: 1, label: "accounting.method.cash" },
    { id: 2, label: "accounting.method.cheque" },
    { id: 3, label: "accounting.method.bank" },
    { id: 4, label: "accounting.method.other" },
];

/** Record a payment to a supplier against an open purchase. */
const SupplierPaymentModal = ({ document, onHide, onSaved }) => {
    const t = useT();
    const money = useMoney();
    const dispatch = useDispatch();
    const [form, setForm] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (document) {
            setForm({
                payment_date: moment().format("YYYY-MM-DD"),
                amount: document.balance,
                payment_type: 1,
                reference: "",
                notes: "",
            });
        }
    }, [document]);

    const submit = (event) => {
        event.preventDefault();
        const amount = Number(form.amount);
        if (!amount || amount <= 0 || amount > Number(document.balance) + 0.005) {
            dispatch(addToast({ text: t("accounting.pay.invalid-amount", { balance: money(document.balance) }), type: toastType.ERROR }));
            return;
        }
        setSaving(true);
        apiConfig
            .post(`accounting/purchases/${document.id}/payments`, { ...form, amount })
            .then(() => {
                dispatch(addToast({ text: t("accounting.pay.saved") }));
                onSaved();
            })
            .catch((err) =>
                dispatch(addToast({ text: err?.response?.data?.message || t("accounting.pay.failed"), type: toastType.ERROR }))
            )
            .finally(() => setSaving(false));
    };

    return (
        <Modal show={!!document} onHide={onHide} centered>
            <Form onSubmit={submit}>
                <Modal.Header closeButton>
                    <Modal.Title>{t("accounting.pay.title")}</Modal.Title>
                </Modal.Header>
                {document && (
                    <Modal.Body>
                        <div className="bg-light rounded p-4 mb-5">
                            <div className="fw-bold">{document.party}</div>
                            <div className="text-gray-600">
                                {document.reference} · {document.date}
                            </div>
                            <div className="mt-2">
                                {t("accounting.balance")}: <span className="fw-bolder">{money(document.balance)}</span>
                            </div>
                        </div>
                        <div className="row g-4">
                            <div className="col-md-6">
                                <Form.Label>{t("accounting.date")}</Form.Label>
                                <Form.Control type="date" required value={form.payment_date || ""} onChange={(e) => setForm({ ...form, payment_date: e.target.value })} />
                            </div>
                            <div className="col-md-6">
                                <Form.Label>{t("accounting.amount")}</Form.Label>
                                <Form.Control type="number" required min="0.01" step="0.01" max={document.balance} value={form.amount ?? ""} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
                            </div>
                            <div className="col-md-6">
                                <Form.Label>{t("accounting.method")}</Form.Label>
                                <Form.Select value={form.payment_type || 1} onChange={(e) => setForm({ ...form, payment_type: Number(e.target.value) })}>
                                    {PAYMENT_TYPES.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {t(p.label)}
                                        </option>
                                    ))}
                                </Form.Select>
                            </div>
                            <div className="col-md-6">
                                <Form.Label>{t("accounting.reference")}</Form.Label>
                                <Form.Control value={form.reference || ""} maxLength={191} placeholder={t("accounting.pay.reference-hint")} onChange={(e) => setForm({ ...form, reference: e.target.value })} />
                            </div>
                            <div className="col-12">
                                <Form.Label>{t("accounting.notes")}</Form.Label>
                                <Form.Control as="textarea" rows={2} value={form.notes || ""} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                            </div>
                        </div>
                    </Modal.Body>
                )}
                <Modal.Footer>
                    <Button variant="secondary" onClick={onHide}>
                        {t("globally.cancel-btn")}
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {t("accounting.pay.submit")}
                    </Button>
                </Modal.Footer>
            </Form>
        </Modal>
    );
};

export default SupplierPaymentModal;
