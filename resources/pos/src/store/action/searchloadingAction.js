import {constants} from '../../constants';

export const setSearchLoading = (isLoad) => {
    return {type: constants.IS_SEARCH_LOADING, payload: isLoad};
};
