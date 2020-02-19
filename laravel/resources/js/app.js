/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

require('./bootstrap');

window.Vue = require('vue');
window.VueCookie = require('vue-cookie');
window.Vue.use(window.VueCookie);

/**
 * The following block of code may be used to automatically register your
 * Vue components. It will recursively scan this directory for the Vue
 * components and automatically register them with their "basename".
 *
 * Eg. ./components/ExampleComponent.vue -> <example-component></example-component>
 */

// const files = require.context('./', true, /\.vue$/i)
// files.keys().map(key => Vue.component(key.split('/').pop().split('.')[0], files(key).default))

Vue.component('example-component', require('./components/ExampleComponent.vue').default);
Vue.component('remove-button', require('./components/RemoveButton.vue').default);
Vue.component('new_course-component', require('./components/NewCourseComponent.vue').default);
Vue.component('cart-item', require('./components/CartItem.vue').default);

/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */

import * as VueGoogleMaps from 'vue2-google-maps';
Vue.use(VueGoogleMaps, {
  load: {
    key: process.env.MIX_GOOGLE_MAP_API,
    libraries: "places"
  }
});

import Vuetify from 'vuetify'
Vue.use(Vuetify)

import StarRating from 'vue-star-rating'
Vue.component('star-rating', require('vue-star-rating').default);

Vue.component('example-component', require('./components/ExampleComponent.vue').default);
Vue.component('remove-button', require('./components/RemoveButton.vue').default);
Vue.component('new_course-component', require('./components/NewCourseComponent.vue').default);
Vue.component('cart-item', require('./components/CartItem.vue').default);
Vue.component('cancel-button', require('./components/CancelButton.vue').default);

const app = new Vue({
    el: '#app',
    vuetify: new Vuetify(),
    data: {
        activeRemove: 'cancelbtn',
        activeOwn: 'ownbtn'
      },
    methods: {
      addCart: function(elementId){
        // set cookie for '1' day
        if (this.$cookie.get('cart') == null){
          this.$cookie.set('cart',[elementId] ,1);  // TODO:insert first item
        }else{
          let tmp = this.$cookie.get('cart');
          this.$cookie.delete('cart');
          tmp.push(elementId);                      // TODO:insert new item
          this.$cookie.set('cart',tmp,1);
        }
      }
    }
});
