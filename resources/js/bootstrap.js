import _ from 'lodash';
window._ = _;

import axios from 'axios';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios = axios;
