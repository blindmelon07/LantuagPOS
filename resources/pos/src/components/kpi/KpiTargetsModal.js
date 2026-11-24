import React, { useEffect, useState } from "react";
import { Modal, Button, Form, Table } from "react-bootstrap";
import { useDispatch } from "react-redux";
import apiConfig from "../../config/apiConfig";
import { addToast } from "../../store/action/toastAction";
import { toastType } from "../../constants";
import { financeApi, useT, useMoney, EmptyRow } from "../finance/financeShared";

const emptyForm = { user_id: "", sales_target: "", transactions_target: "" };

/** Set monthly sales targets for the whole store or for individual cashiers. */
const KpiTargetsModal = ({ show, period: initialPeriod, onHide, onSaved }) => {
    const t = useT();
    const money = useMoney();
    const dispatch = useDispatch();
    const [period, setPeriod] = useState(initialPeriod);
    const [targets, setTargets] = useState([]);
    const [users, setUsers] = useState([]);
    const [form, setForm] = useState(emptyForm);
    const [saving, setSaving] = useState(false);

    const load = (p) =>
        financeApi("kpi/targets", { period: p }).then((data) => {
            setTargets(data.targets);
            setUsers(data.users);
        });

    useEffect(() => {
        if (show) {
            setPeriod(initialPeriod);
            setForm(emptyForm);
            load(initialPeriod);
        }
    }, [show, initialPeriod]);

    const userName = (id) => (id ? users.find((u) => u.id === id)?.name || "—" : t("kpi.targets.whole-store"));

    const error = (err) =>
        dispatch(addToast({ text: err?.response?.data?.message || t("kpi.targets.save-failed"), type: toastType.ERROR }));

    const save = (event) => {
        event.preventDefault();
        if (form.sales_target === "" || Number(form.sales_target) < 0) {
            dispatch(addToast({ text: t("kpi.targets.amount-required"), type: toastType.ERROR }));
            return;
        }
        setSaving(true);
        apiConfig
            .post("kpi/targets", {
                period,
                user_id: form.user_id ? Number(form.user_id) : null,
                sales_target: Number(form.sales_target),
                transactions_target: form.transactions_target === "" ? null : Number(form.transactions_target),
            })
            .then(() => {
                dispatch(addToast({ text: t("kpi.targets.saved") }));
                setForm(emptyForm);
                load(period);
                onSaved && onSaved();
            })
            .catch(error)
            .finally(() => setSaving(false));
    };

    const remove = (id) =>
        apiConfig
            .delete(`kpi/targets/${id}`)
            .then(() => {
                load(period);
                onSaved && onSaved();
            })
            .catch(error);

    const edit = (target) =>
        setForm({
            user_id: target.user_id || "",
            sales_target: target.sales_target,
            transactions_target: target.transactions_target ?? "",
        });

    return (
        <Modal show={show} onHide={onHide} size="lg" centered>
            <Modal.Header closeButton>
                <Modal.Title>{t("kpi.targets.set")}</Modal.Title>
            </Modal.Header>
            <Modal.Body>
                <Form onSubmit={save}>
                    <div className="row g-3 align-items-end">
                        <div className="col-md-3">
                            <Form.Label>{t("kpi.targets.month")}</Form.Label>
                            <Form.Control
                                type="month"
                                value={period}
                                onChange={(e) => {
                                    setPeriod(e.target.value);
                                    load(e.target.value);
                                }}
                            />
                        </div>
                        <div className="col-md-3">
                            <Form.Label>{t("kpi.targets.for")}</Form.Label>
                            <Form.Select value={form.user_id} onChange={(e) => setForm({ ...form, user_id: e.target.value })}>
                                <option value="">{t("kpi.targets.whole-store")}</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </Form.Select>
                        </div>
                        <div className="col-md-2">
                            <Form.Label>{t("kpi.targets.sales")}</Form.Label>
                            <Form.Control type="number" min="0" step="0.01" value={form.sales_target} onChange={(e) => setForm({ ...form, sales_target: e.target.value })} />
                        </div>
                        <div className="col-md-2">
                            <Form.Label>{t("kpi.transactions")}</Form.Label>
                            <Form.Control type="number" min="0" step="1" value={form.transactions_target} placeholder={t("kpi.targets.optional")} onChange={(e) => setForm({ ...form, transactions_target: e.target.value })} />
                        </div>
                        <div className="col-md-2">
                            <Button type="submit" className="w-100" disabled={saving}>
                                {t("globally.save-btn")}
                            </Button>
                        </div>
                    </div>
                </Form>

                <Table responsive className="align-middle mt-6 mb-0">
                    <thead>
                        <tr className="text-gray-600">
                            <th>{t("kpi.targets.for")}</th>
                            <th className="text-end">{t("kpi.targets.sales")}</th>
                            <th className="text-end">{t("kpi.transactions")}</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {targets.length === 0 ? (
                            <EmptyRow colSpan={4} text={t("kpi.targets.none")} />
                        ) : (
                            targets.map((target) => (
                                <tr key={target.id}>
                                    <td>{userName(target.user_id)}</td>
                                    <td className="text-end">{money(target.sales_target)}</td>
                                    <td className="text-end">{target.transactions_target ?? "—"}</td>
                                    <td className="text-end text-nowrap">
                                        <Button size="sm" variant="outline-primary" className="me-2" onClick={() => edit(target)}>
                                            {t("kpi.edit")}
                                        </Button>
                                        <Button size="sm" variant="outline-danger" onClick={() => remove(target.id)}>
                                            {t("globally.delete.tooltip.label")}
                                        </Button>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </Modal.Body>
        </Modal>
    );
};

export default KpiTargetsModal;
