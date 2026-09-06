import React, { useState } from 'react';
import { Button } from 'react-bootstrap-v5';
import AdminFiscalYearForm from './AdminFiscalYearForm';
import { getFormattedMessage } from '../../../shared/sharedMethod';


const AdminCreateFiscalYear = () => {
    const [show, setShow] = useState(false);

    const handleClose = () => {
        setShow(!show);
    };

    return (
        <div className='text-end w-sm-auto'>
            <Button variant='primary mb-lg-0 mb-4' onClick={handleClose}>
                {getFormattedMessage('Create')}
            </Button>
            <AdminFiscalYearForm handleClose={handleClose} show={show} />
        </div>
    );
};

export default AdminCreateFiscalYear;